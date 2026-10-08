<?php

namespace App\Http\Controllers;

use App\Models\CropTask;
use App\Models\CropCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CropTaskController extends Controller
{
    private const TASK_TYPES = [
        'planting',
        'watering',
        'fertilizing',
        'weeding',
        'pest_control',
        'disease_management',
        'pruning',
        'harvesting',
        'general',
        'other',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_cycle_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['scheduled', 'in_progress', 'completed', 'cancelled', 'overdue'])],
            'assigned_to' => ['nullable', 'uuid'],
            'task_type' => ['nullable', Rule::in(self::TASK_TYPES)],
            'due_date' => ['nullable', Rule::in(['today', 'overdue', 'this_week'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->farmTasks($request)->with(['cropCycle', 'assignedUser']);

        if (!empty($validated['crop_cycle_id'])) {
            $query->forCrop($validated['crop_cycle_id']);
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['assigned_to'])) {
            $query->assignedTo($validated['assigned_to']);
        }
        if (!empty($validated['task_type'])) {
            $query->byTaskType($validated['task_type']);
        }
        if (!empty($validated['start_date'])) {
            $query->whereDate('scheduled_date', '>=', $validated['start_date']);
        }
        if (!empty($validated['end_date'])) {
            $query->whereDate('scheduled_date', '<=', $validated['end_date']);
        }

        match ($validated['due_date'] ?? null) {
            'today' => $query->dueToday(),
            'overdue' => $query->overdue(),
            'this_week' => $query->dueThisWeek(),
            default => null,
        };

        $tasks = $query->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->paginate($validated['limit'] ?? 50);

        return response()->json([
            'success' => true,
            'data' => [
                'tasks' => $tasks->getCollection()->map(fn (CropTask $task) => $this->taskResource($task))->values(),
                'pagination' => [
                    'current_page' => $tasks->currentPage(),
                    'last_page' => $tasks->lastPage(),
                    'per_page' => $tasks->perPage(),
                    'total' => $tasks->total(),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $taskId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['task' => $this->taskResource($this->task($request, $taskId))],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->taskRules(),
            'crop_cycle_id' => ['required', 'uuid'],
        ]);
        $farmId = $request->header('X-Tenant-ID');

        abort_unless($farmId, 422, 'An active farm is required.');
        abort_unless($request->user()->hasAccessToFarm($farmId), 403, 'You do not have access to this farm.');

        $cropCycle = CropCycle::query()
            ->where('farm_id', $farmId)
            ->findOrFail($data['crop_cycle_id']);

        if (!empty($data['assigned_to'])) {
            $assignee = User::findOrFail($data['assigned_to']);
            abort_unless($assignee->hasAccessToFarm($farmId), 422, 'The assigned worker must belong to the active farm.');
        }

        $task = DB::transaction(fn () => $cropCycle->tasks()->create([
            ...$data,
            'status' => 'scheduled',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Task created successfully.',
            'data' => ['task' => $this->taskResource($task->load(['cropCycle', 'assignedUser']))],
        ], 201);
    }

    public function update(Request $request, string $taskId): JsonResponse
    {
        $task = $this->task($request, $taskId);

        if (in_array($task->status, ['completed', 'cancelled'], true)) {
            return response()->json(['success' => false, 'message' => 'Completed or cancelled tasks cannot be edited.'], 409);
        }

        $data = $request->validate($this->taskRules(true));
        $task->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Task updated successfully.',
            'data' => ['task' => $this->taskResource($task->fresh(['cropCycle', 'assignedUser']))],
        ]);
    }

    public function start(Request $request, string $taskId): JsonResponse
    {
        $task = $this->task($request, $taskId);

        if (!$task->canStart()) {
            return response()->json(['success' => false, 'message' => 'Only due, scheduled tasks can be started.'], 409);
        }

        $task->start($request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Task started successfully.',
            'data' => ['task' => $this->taskResource($task->fresh(['cropCycle', 'assignedUser']))],
        ]);
    }

    public function complete(Request $request, string $taskId): JsonResponse
    {
        $task = $this->task($request, $taskId);
        $data = $request->validate([
            'actual_duration' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'completion_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(fn () => $task->complete($data));

        return response()->json([
            'success' => true,
            'message' => 'Task completed successfully.',
            'data' => ['task' => $this->taskResource($task->fresh(['cropCycle', 'assignedUser']))],
        ]);
    }

    public function cancel(Request $request, string $taskId): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $task = $this->task($request, $taskId);

        if ($task->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Completed tasks cannot be cancelled.'], 409);
        }

        $task->cancel($data['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Task cancelled successfully.',
            'data' => ['task' => $this->taskResource($task->fresh(['cropCycle', 'assignedUser']))],
        ]);
    }

    public function skip(Request $request, string $taskId): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $task = $this->task($request, $taskId);

        if ($task->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Completed tasks cannot be skipped.'], 409);
        }

        $task->skip($data['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Task skipped and cancelled.',
            'data' => ['task' => $this->taskResource($task->fresh(['cropCycle', 'assignedUser']))],
        ]);
    }

    public function reschedule(Request $request, string $taskId): JsonResponse
    {
        $data = $request->validate([
            'new_date' => ['required', 'date', 'after_or_equal:today'],
            'new_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $task = $this->task($request, $taskId);

        if ($task->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Completed tasks cannot be rescheduled.'], 409);
        }

        $task->reschedule($data['new_date'], $data['reason'] ?? null);
        if (array_key_exists('new_time', $data)) {
            $task->update(['scheduled_time' => $data['new_time']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Task rescheduled successfully.',
            'data' => ['task' => $this->taskResource($task->fresh(['cropCycle', 'assignedUser']))],
        ]);
    }

    public function destroy(Request $request, string $taskId): JsonResponse
    {
        $task = $this->task($request, $taskId);

        if ($task->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Completed tasks must remain in the production record.'], 409);
        }

        $task->delete();

        return response()->json(['success' => true, 'message' => 'Task deleted successfully.']);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $tasks = $this->farmTasks($request)->with('cropCycle')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_tasks' => $tasks->count(),
                    'scheduled_tasks' => $tasks->where('status', 'scheduled')->count(),
                    'in_progress_tasks' => $tasks->where('status', 'in_progress')->count(),
                    'completed_tasks' => $tasks->where('status', 'completed')->count(),
                    'overdue_tasks' => $tasks->filter->is_overdue->count(),
                    'total_estimated_minutes' => $tasks->sum('estimated_duration'),
                    'total_actual_minutes' => $tasks->sum('actual_duration'),
                ],
                'tasks' => $tasks->sortBy('scheduled_date')->take(10)->map(fn (CropTask $task) => $this->taskResource($task))->values(),
            ],
        ]);
    }

    private function taskRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'task_name' => [$required, 'string', 'max:255'],
            'task_type' => [$required, Rule::in(self::TASK_TYPES)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'scheduled_date' => [$required, 'date', 'after_or_equal:today'],
            'scheduled_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'estimated_duration' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
            'assigned_to' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
        ];
    }

    private function farmTasks(Request $request): Builder
    {
        $farmId = $request->header('X-Tenant-ID') ?? $request->input('farm_id');

        abort_unless($farmId, 422, 'An active farm is required.');
        abort_unless($request->user()->hasAccessToFarm($farmId), 403, 'You do not have access to this farm.');

        return CropTask::query()->whereHas('cropCycle', fn (Builder $query) => $query->where('farm_id', $farmId));
    }

    private function task(Request $request, string $taskId): CropTask
    {
        return $this->farmTasks($request)
            ->with(['cropCycle', 'assignedUser'])
            ->findOrFail($taskId);
    }

    private function taskResource(CropTask $task): array
    {
        return [
            'id' => $task->id,
            'crop_cycle_id' => $task->crop_cycle_id,
            'crop_name' => $task->cropCycle?->crop_name,
            'crop_variety' => $task->cropCycle?->variety,
            'task_name' => $task->task_name,
            'task_type' => $task->task_type,
            'description' => $task->description,
            'scheduled_date' => $task->scheduled_date?->toDateString(),
            'scheduled_time' => $task->formatted_scheduled_time,
            'completed_date' => $task->completed_date?->toDateString(),
            'status' => $task->status,
            'priority' => $task->priority,
            'estimated_duration' => $task->estimated_duration,
            'actual_duration' => $task->actual_duration,
            'assigned_to' => $task->assigned_to,
            'assigned_user_name' => $task->assignedUser?->name,
            'completion_notes' => $task->completion_notes,
            'is_overdue' => $task->is_overdue,
            'is_due_today' => $task->is_due_today,
            'can_start' => $task->canStart(),
            'created_at' => $task->created_at,
            'updated_at' => $task->updated_at,
        ];
    }
}
