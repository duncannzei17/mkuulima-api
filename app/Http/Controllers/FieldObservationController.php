<?php

namespace App\Http\Controllers;

use App\Events\FieldObservationApproved;
use App\Events\FieldObservationCreated;
use App\Events\FieldObservationResolved;
use App\Models\Bed;
use App\Models\CropCycle;
use App\Models\CropTask;
use App\Models\FieldObservation;
use App\Models\ObservationTag;
use App\Models\ObservationType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FieldObservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_cycle_id' => ['nullable', 'uuid'],
            'bed_id' => ['nullable', 'uuid'],
            'observation_type' => ['nullable', 'string', 'max:80'],
            'severity' => ['nullable', Rule::in(array_keys(FieldObservation::SEVERITY_LEVELS))],
            'status' => ['nullable', Rule::in(array_keys(FieldObservation::STATUSES))],
            'is_critical' => ['nullable', 'boolean'],
            'is_resolved' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'worker_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->filteredQuery($validated)
            ->with(['cropCycle', 'bed', 'worker', 'createdBy', 'tags'])
            ->orderByDesc('observation_date')
            ->orderByDesc('created_at');

        $observations = $query->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => $observations,
            'meta' => [
                'total_observations' => $observations->total(),
                'critical_count' => FieldObservation::where('is_critical', true)->where('is_resolved', false)->count(),
                'pending_count' => FieldObservation::where('status', 'pending')->count(),
                'high_severity_count' => FieldObservation::where('severity', 'high')->where('is_resolved', false)->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateObservation($request);
        $this->validateRelationships($request, $data);

        $observation = DB::transaction(function () use ($request, $data) {
            $tags = $data['tags'] ?? [];
            unset($data['tags'], $data['photos']);

            $data['created_by'] = $request->user()->id;
            $data['worker_id'] ??= $request->user()->id;
            $data['is_critical'] = $data['severity'] === 'high';
            $data['requires_immediate_action'] = $data['severity'] === 'high';
            $data['photo_urls'] = $this->storePhotos($request, $data['photo_urls'] ?? []);

            if ($this->canManage($request)) {
                $data['status'] = 'approved';
                $data['approved_by'] = $request->user()->id;
                $data['approved_at'] = now();
            } else {
                $data['status'] = 'pending';
            }

            $observation = FieldObservation::create($data);
            $this->replaceTags($observation, $tags);

            if ($observation->status === 'approved') {
                $observation->updateSeasonHealthScore();
                $observation->generateAIInsights();
            }

            return $observation;
        });

        $observation->load($this->relations());
        event(new FieldObservationCreated($observation));

        return response()->json([
            'success' => true,
            'message' => $observation->status === 'pending'
                ? 'Observation submitted for approval'
                : 'Observation recorded successfully',
            'data' => $observation,
        ], 201);
    }

    public function show(FieldObservation $observation): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $observation->load([...$this->relations(), 'approvedBy', 'resolvedBy', 'relatedTask', 'relatedExpense']),
        ]);
    }

    public function update(Request $request, FieldObservation $observation): JsonResponse
    {
        abort_unless(
            $this->canManage($request)
                || ($observation->created_by === $request->user()->id && $observation->status === 'pending'),
            403,
            'You can only edit your own pending observations.'
        );

        $data = $this->validateObservation($request, true);
        $merged = array_merge($observation->only(['crop_cycle_id', 'bed_id', 'worker_id']), $data);
        $this->validateRelationships($request, $merged);

        $oldCropCycleId = $observation->crop_cycle_id;
        $oldBedId = $observation->bed_id;

        DB::transaction(function () use ($request, $observation, $data) {
            $tags = array_key_exists('tags', $data) ? $data['tags'] : null;
            unset($data['tags'], $data['photos']);

            if (isset($data['severity'])) {
                $data['is_critical'] = $data['severity'] === 'high';
                $data['requires_immediate_action'] = $data['severity'] === 'high';
            }
            if ($request->hasFile('photos')) {
                $data['photo_urls'] = $this->storePhotos($request, $data['photo_urls'] ?? $observation->photo_urls ?? []);
            }

            $observation->update($data);
            if ($tags !== null) {
                $this->replaceTags($observation, $tags);
            }

            if ($observation->status === 'approved') {
                $observation->updateSeasonHealthScore();
                $observation->generateAIInsights();
            }
        });

        $this->syncPreviousHealth($oldCropCycleId, $oldBedId, $observation);

        return response()->json([
            'success' => true,
            'message' => 'Observation updated successfully',
            'data' => $observation->fresh($this->relations()),
        ]);
    }

    public function approve(Request $request, FieldObservation $observation): JsonResponse
    {
        $this->requireManager($request);
        abort_unless($observation->status === 'pending', 409, 'Only pending observations can be approved.');

        DB::transaction(fn () => $observation->approve($request->user()));
        event(new FieldObservationApproved($observation));

        return response()->json([
            'success' => true,
            'message' => 'Observation approved successfully',
            'data' => $observation->fresh([...$this->relations(), 'approvedBy']),
        ]);
    }

    public function reject(Request $request, FieldObservation $observation): JsonResponse
    {
        $this->requireManager($request);
        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'max:500']]);
        abort_unless($observation->status === 'pending', 409, 'Only pending observations can be rejected.');

        $observation->reject($request->user(), $validated['rejection_reason']);

        return response()->json([
            'success' => true,
            'message' => 'Observation rejected successfully',
            'data' => $observation->fresh([...$this->relations(), 'approvedBy']),
        ]);
    }

    public function resolve(Request $request, FieldObservation $observation): JsonResponse
    {
        $this->requireManager($request);
        $validated = $request->validate(['resolution_notes' => ['required', 'string', 'max:1000']]);
        abort_unless($observation->status === 'approved', 409, 'Only approved observations can be resolved.');
        abort_if($observation->is_resolved, 409, 'Observation is already resolved.');

        DB::transaction(fn () => $observation->resolve($request->user(), $validated['resolution_notes']));
        event(new FieldObservationResolved($observation));

        return response()->json([
            'success' => true,
            'message' => 'Observation resolved successfully',
            'data' => $observation->fresh([...$this->relations(), 'resolvedBy']),
        ]);
    }

    public function bulkApproval(Request $request): JsonResponse
    {
        $this->requireManager($request);
        $validated = $request->validate([
            'observation_ids' => ['required', 'array', 'min:1', 'max:100'],
            'observation_ids.*' => ['uuid', 'distinct', 'exists:field_observations,id'],
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'rejection_reason' => [Rule::requiredIf($request->input('action') === 'reject'), 'nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $validated) {
            $observations = FieldObservation::whereIn('id', $validated['observation_ids'])->lockForUpdate()->get();
            abort_unless($observations->count() === count($validated['observation_ids']), 422, 'One or more observations do not exist.');
            abort_if($observations->contains(fn ($item) => $item->status !== 'pending'), 409, 'All selected observations must be pending.');

            foreach ($observations as $observation) {
                if ($validated['action'] === 'approve') {
                    $observation->approve($request->user());
                    event(new FieldObservationApproved($observation));
                } else {
                    $observation->reject($request->user(), $validated['rejection_reason']);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => count($validated['observation_ids']).' observations processed successfully',
            'data' => ['processed' => count($validated['observation_ids'])],
        ]);
    }

    public function createTask(Request $request, FieldObservation $observation): JsonResponse
    {
        $this->requireManager($request);
        abort_unless($observation->crop_cycle_id, 422, 'A crop cycle is required before creating a task.');
        abort_if($observation->related_task_id, 409, 'This observation already has a related task.');

        $validated = $request->validate([
            'task_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'scheduled_date' => ['required', 'date', 'after_or_equal:today'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'assigned_to' => ['nullable', 'uuid', 'exists:users,id'],
            'estimated_duration' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        if (!empty($validated['assigned_to'])) {
            $assignee = User::findOrFail($validated['assigned_to']);
            abort_unless($assignee->hasAccessToFarm($this->farmId($request)), 422, 'The assignee does not belong to this farm.');
        }

        $task = DB::transaction(function () use ($observation, $validated) {
            $task = CropTask::create([
                ...$validated,
                'crop_cycle_id' => $observation->crop_cycle_id,
                'task_type' => $this->taskTypeFor($observation->observation_type),
                'status' => 'scheduled',
                'description' => $validated['description'] ?? $observation->description,
                'task_data' => ['field_observation_id' => $observation->id],
            ]);
            $observation->update(['related_task_id' => $task->id]);
            return $task;
        });

        return response()->json([
            'success' => true,
            'message' => 'Follow-up task created successfully',
            'data' => $task->load('assignedUser'),
        ], 201);
    }

    public function destroy(Request $request, FieldObservation $observation): JsonResponse
    {
        $this->requireManager($request);
        abort_if($observation->related_task_id, 409, 'Delete or unlink the related task before deleting this observation.');

        $cropCycleId = $observation->crop_cycle_id;
        $bedId = $observation->bed_id;
        $observation->delete();
        $this->syncHealthFor($cropCycleId, $bedId);

        return response()->json(['success' => true, 'message' => 'Observation deleted successfully']);
    }

    public function timeline(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_cycle_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $observations = $this->filteredQuery($validated)
            ->with($this->relations())
            ->where('status', 'approved')
            ->orderByDesc('observation_date')
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => $observations->getCollection()->groupBy(fn ($item) => $item->observation_date->format('Y-m-d')),
            'pagination' => [
                'current_page' => $observations->currentPage(),
                'last_page' => $observations->lastPage(),
                'per_page' => $observations->perPage(),
                'total' => $observations->total(),
            ],
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_cycle_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $query = $this->filteredQuery($validated)->where('status', 'approved');

        $analytics = [
            'total_observations' => (clone $query)->count(),
            'by_severity' => (clone $query)->selectRaw('severity, count(*) as count')->groupBy('severity')->pluck('count', 'severity'),
            'by_type' => (clone $query)->selectRaw('observation_type, count(*) as count')->groupBy('observation_type')->pluck('count', 'observation_type'),
            'critical_observations' => (clone $query)->where('is_critical', true)->count(),
            'unresolved_observations' => (clone $query)->where('is_resolved', false)->count(),
            'avg_resolution_time' => (clone $query)->whereNotNull('resolved_at')
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (resolved_at - created_at))/86400) as avg_days')->value('avg_days'),
            'total_estimated_impact' => (float) (clone $query)->sum('cost_impact_estimate'),
            'observations_trend' => (clone $query)->selectRaw('DATE(observation_date) as date, count(*) as count')
                ->groupByRaw('DATE(observation_date)')->orderByRaw('DATE(observation_date)')->pluck('count', 'date'),
        ];

        return response()->json(['success' => true, 'data' => $analytics]);
    }

    public function seasonSummary(Request $request, CropCycle $cropCycle): JsonResponse
    {
        $query = FieldObservation::where('crop_cycle_id', $cropCycle->id)->where('status', 'approved');
        $observations = (clone $query)->with(['bed', 'tags'])->orderBy('observation_date')->get();
        $recommendations = $observations->flatMap(fn ($item) => $item->recommendations ?? [])
            ->unique('description')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'crop_cycle_id' => $cropCycle->id,
                'crop_name' => $cropCycle->crop_name,
                'total_observations' => $observations->count(),
                'resolved_observations' => $observations->where('is_resolved', true)->count(),
                'critical_observations' => $observations->where('is_critical', true)->count(),
                'season_health_score' => max(0, 100 + $observations->sum('season_health_impact')),
                'by_type' => $observations->countBy('observation_type'),
                'by_severity' => $observations->countBy('severity'),
                'recommendations' => $recommendations,
                'timeline' => $observations,
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'crop_cycle_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', Rule::in(array_keys(FieldObservation::STATUSES))],
        ]);

        $rows = $this->filteredQuery($validated)->with(['bed', 'worker', 'tags'])
            ->orderByDesc('observation_date')->get();

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Date', 'Type', 'Severity', 'Status', 'Description', 'Bed', 'Observer', 'Resolved', 'Tags']);
            foreach ($rows as $row) {
                fputcsv($output, [
                    $row->observation_date->toDateString(),
                    $row->observation_type,
                    $row->severity,
                    $row->status,
                    $row->description,
                    $row->bed?->name,
                    $row->worker?->name,
                    $row->is_resolved ? 'Yes' : 'No',
                    $row->tags->pluck('tag')->implode(', '),
                ]);
            }
            fclose($output);
        }, 'field-observations-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function getObservationTypes(): JsonResponse
    {
        $types = collect(FieldObservation::OBSERVATION_TYPES)
            ->merge(ObservationType::where('is_active', true)->orderBy('name')->pluck('name', 'slug'));

        return response()->json(['success' => true, 'data' => $types]);
    }

    public function storeObservationType(Request $request): JsonResponse
    {
        $this->requireManager($request);
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $slug = Str::slug($validated['name'], '_');
        abort_if(array_key_exists($slug, FieldObservation::OBSERVATION_TYPES), 409, 'This observation type already exists.');

        $type = ObservationType::firstOrCreate(
            ['slug' => $slug],
            ['name' => $validated['name'], 'is_active' => true, 'created_by' => $request->user()->id]
        );
        if (!$type->is_active) {
            $type->update(['name' => $validated['name'], 'is_active' => true]);
        }

        return response()->json([
            'success' => true,
            'message' => $type->wasRecentlyCreated ? 'Observation type created successfully' : 'Observation type restored successfully',
            'data' => $type,
        ], $type->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyObservationType(Request $request, ObservationType $type): JsonResponse
    {
        $this->requireManager($request);
        abort_if(FieldObservation::where('observation_type', $type->slug)->exists(), 409, 'This type is in use and cannot be removed.');
        $type->delete();

        return response()->json(['success' => true, 'message' => 'Observation type removed successfully']);
    }

    public function getPredefinedTags(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ObservationTag::PREDEFINED_TAGS]);
    }

    private function validateObservation(Request $request, bool $updating = false): array
    {
        $prefix = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'crop_cycle_id' => ['nullable', 'uuid', 'exists:crop_cycles,id'],
            'bed_id' => ['nullable', 'uuid', 'exists:beds,id'],
            'worker_id' => ['nullable', 'uuid', 'exists:users,id'],
            'observation_date' => [$prefix, 'date', 'before_or_equal:today'],
            'observation_type' => [$prefix, 'string', Rule::in(array_keys($this->observationTypes()))],
            'severity' => [$prefix, Rule::in(array_keys(FieldObservation::SEVERITY_LEVELS))],
            'description' => [$prefix, 'string', 'max:2000'],
            'location_notes' => ['nullable', 'string', 'max:500'],
            'photo_urls' => ['nullable', 'array', 'max:8'],
            'photo_urls.*' => ['string', 'url', 'max:2048'],
            'photos' => ['nullable', 'array', 'max:8'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'weather_conditions' => ['nullable', 'string', 'max:100'],
            'temperature' => ['nullable', 'numeric', 'between:-50,70'],
            'humidity' => ['nullable', 'numeric', 'between:0,100'],
            'estimated_impact_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'affected_area' => ['nullable', 'numeric', 'min:0'],
            'affected_area_unit' => ['nullable', Rule::in(['sqm', 'hectares', 'acres'])],
            'growth_stage' => ['nullable', 'string', 'max:50'],
            'days_after_planting' => ['nullable', 'integer', 'min:0'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50', 'distinct'],
        ]);
    }

    private function validateRelationships(Request $request, array $data): void
    {
        if (!empty($data['crop_cycle_id'])) {
            $cropCycle = CropCycle::findOrFail($data['crop_cycle_id']);
            abort_unless($cropCycle->farm_id === $this->farmId($request), 422, 'The crop cycle does not belong to this farm.');
        }

        if (!empty($data['bed_id'])) {
            $bed = Bed::findOrFail($data['bed_id']);
            abort_unless($bed->farm_id === $this->farmId($request), 422, 'The bed does not belong to this farm.');
            if (!empty($data['crop_cycle_id']) && $bed->current_crop_cycle_id) {
                abort_unless($bed->current_crop_cycle_id === $data['crop_cycle_id'], 422, 'The bed is assigned to a different crop cycle.');
            }
        }

        if (!empty($data['worker_id'])) {
            $worker = User::findOrFail($data['worker_id']);
            abort_unless($worker->hasAccessToFarm($this->farmId($request)), 422, 'The observer does not belong to this farm.');
            if (!$this->canManage($request)) {
                abort_unless($worker->id === $request->user()->id, 403, 'Workers can only submit observations for themselves.');
            }
        }
    }

    private function filteredQuery(array $filters): Builder
    {
        $query = FieldObservation::query();
        foreach (['crop_cycle_id', 'bed_id', 'observation_type', 'severity', 'status', 'worker_id'] as $filter) {
            if (!empty($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }
        foreach (['is_critical', 'is_resolved'] as $filter) {
            if (array_key_exists($filter, $filters) && $filters[$filter] !== null) {
                $query->where($filter, filter_var($filters[$filter], FILTER_VALIDATE_BOOL));
            }
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('observation_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('observation_date', '<=', $filters['date_to']);
        }
        return $query;
    }

    private function observationTypes(): array
    {
        return array_merge(
            FieldObservation::OBSERVATION_TYPES,
            ObservationType::where('is_active', true)->pluck('name', 'slug')->all()
        );
    }

    private function replaceTags(FieldObservation $observation, array $tags): void
    {
        $observation->tags()->delete();
        foreach (array_unique(array_map(fn ($tag) => Str::lower(trim($tag)), $tags)) as $tag) {
            if ($tag === '') {
                continue;
            }
            $observation->tags()->create(['tag' => $tag, 'tag_category' => $this->tagCategory($tag)]);
        }
    }

    private function storePhotos(Request $request, array $existing): array
    {
        foreach ($request->file('photos', []) as $photo) {
            $existing[] = Storage::disk('public')->url($photo->store('field-observations', 'public'));
        }
        return array_values(array_unique($existing));
    }

    private function tagCategory(string $tag): string
    {
        foreach (ObservationTag::PREDEFINED_TAGS as $category => $tags) {
            if (in_array(Str::lower($tag), $tags, true)) {
                return $category;
            }
        }
        return 'general';
    }

    private function relations(): array
    {
        return ['cropCycle', 'bed', 'worker', 'createdBy', 'tags'];
    }

    private function farmId(Request $request): string
    {
        $farmId = (string) ($request->header('X-Tenant-ID') ?? $request->input('farm_id', ''));
        abort_if($farmId === '', 422, 'A farm context is required.');
        return $farmId;
    }

    private function canManage(Request $request): bool
    {
        return in_array($request->user()?->getRoleOnFarm($this->farmId($request)), ['owner', 'manager'], true);
    }

    private function requireManager(Request $request): void
    {
        abort_unless($this->canManage($request), 403, 'Only farm owners and managers can perform this action.');
    }

    private function taskTypeFor(string $observationType): string
    {
        return match ($observationType) {
            'pest' => 'pest_control',
            'disease' => 'disease_management',
            'water_stress' => 'watering',
            'nutrient_deficiency' => 'fertilizing',
            default => 'general',
        };
    }

    private function syncPreviousHealth(?string $cropCycleId, ?string $bedId, FieldObservation $observation): void
    {
        if ($cropCycleId !== $observation->crop_cycle_id || $bedId !== $observation->bed_id) {
            $this->syncHealthFor($cropCycleId, $bedId);
        }
    }

    private function syncHealthFor(?string $cropCycleId, ?string $bedId): void
    {
        CropCycle::find($cropCycleId)?->recalculateSeasonHealthScore();
        if (!$bedId) {
            return;
        }
        $bed = Bed::find($bedId);
        if (!$bed) {
            return;
        }
        $impact = FieldObservation::where('bed_id', $bedId)->where('status', 'approved')->get()
            ->sum(fn ($item) => $item->calculateSeasonHealthImpact());
        $bed->update(['health_score' => max(0, min(100, 100 + $impact)), 'health_score_updated_at' => now()]);
    }
}
