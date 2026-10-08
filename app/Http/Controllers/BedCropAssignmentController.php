<?php

namespace App\Http\Controllers;

use App\Models\Bed;
use App\Models\BedCropAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BedCropAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $farmId = $this->farmId($request);
        if (!$farmId || !$this->canView($request, $farmId)) {
            return $this->forbidden();
        }

        $validator = Validator::make(array_merge($request->query(), ['farm_id' => $farmId]), [
            'farm_id' => 'required|uuid',
            'bed_id' => 'nullable|uuid',
            'crop_cycle_id' => 'nullable|uuid',
            'status' => 'nullable|in:planned,planted,growing,harvesting,completed,failed,abandoned',
            'active' => 'nullable|boolean',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = BedCropAssignment::with([
            'bed:id,name,status',
            'cropCycle:id,crop_name,variety',
            'assignedBy:id,name',
            'completedBy:id,name',
        ])->where('farm_id', $farmId);

        $query->when($request->bed_id, fn ($builder, $bedId) => $builder->where('bed_id', $bedId));
        $query->when($request->crop_cycle_id, fn ($builder, $cropCycleId) => $builder->where('crop_cycle_id', $cropCycleId));
        $query->when($request->status, fn ($builder, $status) => $builder->where('assignment_status', $status));
        $query->when($request->has('active'), fn ($builder) => $builder->where('is_active', $request->boolean('active')));
        $query->when($request->date_from, fn ($builder, $date) => $builder->whereDate('start_date', '>=', $date));
        $query->when($request->date_to, fn ($builder, $date) => $builder->whereDate('start_date', '<=', $date));
        $query->when($request->search, function ($builder, $search) {
            $builder->where(function ($nested) use ($search) {
                $nested->where('crop_name', 'like', "%{$search}%")
                    ->orWhere('variety', 'like', "%{$search}%")
                    ->orWhereHas('bed', fn ($bedQuery) => $bedQuery->where('name', 'like', "%{$search}%"));
            });
        });

        $assignments = $query->orderByDesc('start_date')
            ->orderByDesc('created_at')
            ->paginate((int) $request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => [
                'data' => $assignments->items(),
                'meta' => [
                    'current_page' => $assignments->currentPage(),
                    'last_page' => $assignments->lastPage(),
                    'per_page' => $assignments->perPage(),
                    'total' => $assignments->total(),
                ],
            ],
        ]);
    }

    public function show(Request $request, BedCropAssignment $bedCropAssignment): JsonResponse
    {
        $farmId = $this->farmId($request);
        if (!$farmId || $bedCropAssignment->farm_id !== $farmId || !$this->canView($request, $farmId)) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data' => $bedCropAssignment->load(['bed:id,name,status', 'cropCycle:id,crop_name,variety', 'assignedBy:id,name', 'completedBy:id,name']),
        ]);
    }

    public function abandon(Request $request, BedCropAssignment $bedCropAssignment): JsonResponse
    {
        $farmId = $this->farmId($request);
        if (!$farmId || $bedCropAssignment->farm_id !== $farmId || !$this->canManage($request, $farmId)) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'completion_notes' => 'required|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $assignment = DB::transaction(function () use ($bedCropAssignment, $request) {
            $assignment = BedCropAssignment::whereKey($bedCropAssignment->id)->lockForUpdate()->firstOrFail();
            if (!$assignment->is_active) {
                return null;
            }

            $bed = Bed::whereKey($assignment->bed_id)->lockForUpdate()->firstOrFail();
            $assignment->update([
                'assignment_status' => 'abandoned',
                'is_active' => false,
                'actual_end_date' => now()->toDateString(),
                'completion_notes' => $request->completion_notes,
                'completed_by' => $request->user()->id,
                'status_updated_at' => now(),
            ]);
            $bed->update([
                'status' => $bed->needs_maintenance ? 'under_maintenance' : 'empty',
                'current_crop_cycle_id' => null,
            ]);

            return $assignment->fresh(['bed:id,name,status', 'cropCycle:id,crop_name,variety', 'assignedBy:id,name', 'completedBy:id,name']);
        });

        if (!$assignment) {
            return response()->json([
                'success' => false,
                'message' => 'Only an active assignment can be abandoned',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Crop assignment abandoned',
            'data' => $assignment,
        ]);
    }

    private function farmId(Request $request): ?string
    {
        return $request->header('X-Tenant-ID') ?? $request->input('farm_id');
    }

    private function canView(Request $request, string $farmId): bool
    {
        return $request->user()?->getRoleOnFarm($farmId) !== null;
    }

    private function canManage(Request $request, string $farmId): bool
    {
        return in_array($request->user()?->getRoleOnFarm($farmId), ['owner', 'manager'], true);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You do not have access to crop assignments for this farm',
        ], 403);
    }
}
