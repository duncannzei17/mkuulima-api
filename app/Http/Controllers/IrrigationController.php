<?php

namespace App\Http\Controllers;

use App\Models\Bed;
use App\Models\IrrigationSchedule;
use App\Models\IrrigationSystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class IrrigationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $farmId = $this->farmId($request);

        $systems = IrrigationSystem::with('schedules')
            ->where('farm_id', $farmId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $schedules = IrrigationSchedule::with('irrigationSystem')
            ->where('farm_id', $farmId)
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'systems' => $systems,
                'schedules' => $schedules,
                'stats' => $this->stats($systems, $schedules),
            ],
        ]);
    }

    public function storeSystem(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->systemRules($request));

        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $system = DB::transaction(function () use ($validator, $request) {
            $data = $validator->validated();
            $data['farm_id'] = $this->farmId($request);
            $data['created_by'] = Auth::id();

            $system = IrrigationSystem::create($data);
            $this->syncSystemBeds($system);

            return $system->fresh('schedules');
        });

        return response()->json([
            'success' => true,
            'message' => 'Irrigation system created successfully',
            'data' => $system,
        ], 201);
    }

    public function updateSystem(Request $request, IrrigationSystem $system): JsonResponse
    {
        $this->abortIfWrongFarm($request, $system->farm_id);

        $validator = Validator::make($request->all(), $this->systemRules($request, true));

        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $system = DB::transaction(function () use ($validator, $system) {
            $system->update(array_merge($validator->validated(), [
                'updated_by' => Auth::id(),
            ]));
            $this->syncSystemBeds($system->fresh());

            return $system->fresh('schedules');
        });

        return response()->json([
            'success' => true,
            'message' => 'Irrigation system updated successfully',
            'data' => $system,
        ]);
    }

    public function destroySystem(Request $request, IrrigationSystem $system): JsonResponse
    {
        $this->abortIfWrongFarm($request, $system->farm_id);

        DB::transaction(function () use ($system) {
            $system->update([
                'status' => 'offline',
                'is_active' => false,
                'updated_by' => Auth::id(),
            ]);

            Bed::where('farm_id', $system->farm_id)
                ->whereIn('id', $system->bed_ids ?? [])
                ->update(['has_irrigation' => false]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Irrigation system removed successfully',
        ]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->scheduleRules($request));

        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $data = $validator->validated();
        $data['farm_id'] = $this->farmId($request);
        $data['created_by'] = Auth::id();
        $data['next_run_at'] = $this->nextRunAt($data);

        $schedule = IrrigationSchedule::create($data)->fresh('irrigationSystem');

        return response()->json([
            'success' => true,
            'message' => 'Irrigation schedule created successfully',
            'data' => $schedule,
        ], 201);
    }

    public function updateSchedule(Request $request, IrrigationSchedule $schedule): JsonResponse
    {
        $this->abortIfWrongFarm($request, $schedule->farm_id);

        $validator = Validator::make($request->all(), $this->scheduleRules($request, true));

        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $data = $validator->validated();
        $data['updated_by'] = Auth::id();
        $data['next_run_at'] = $this->nextRunAt(array_merge($schedule->toArray(), $data));

        $schedule->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Irrigation schedule updated successfully',
            'data' => $schedule->fresh('irrigationSystem'),
        ]);
    }

    public function destroySchedule(Request $request, IrrigationSchedule $schedule): JsonResponse
    {
        $this->abortIfWrongFarm($request, $schedule->farm_id);

        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Irrigation schedule deleted successfully',
        ]);
    }

    public function setSystemStatus(Request $request, IrrigationSystem $system): JsonResponse
    {
        $this->abortIfWrongFarm($request, $system->farm_id);

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,scheduled,offline,maintenance',
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $system->update([
            'status' => $validator->validated()['status'],
            'updated_by' => Auth::id(),
        ]);
        $this->syncSystemBeds($system->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Irrigation system status updated successfully',
            'data' => $system->fresh('schedules'),
        ]);
    }

    public function bulkStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'farm_id' => 'nullable|uuid|exists:farms,id',
            'status' => 'required|in:active,scheduled,offline,maintenance',
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $farmId = $this->farmId($request);
        $status = $validator->validated()['status'];

        DB::transaction(function () use ($farmId, $status) {
            IrrigationSystem::where('farm_id', $farmId)
                ->where('is_active', true)
                ->update([
                    'status' => $status,
                    'updated_by' => Auth::id(),
                    'updated_at' => now(),
                ]);

            Bed::where('farm_id', $farmId)->update([
                'has_irrigation' => $status === 'active',
            ]);
        });

        return $this->index($request);
    }

    private function systemRules(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';

        return [
            'farm_id' => $partial ? 'sometimes|uuid|exists:farms,id' : 'nullable|uuid|exists:farms,id',
            'name' => "{$required}|string|max:120",
            'type' => "{$required}|in:drip,sprinkler,mist,flood,manual,other",
            'zone_name' => 'nullable|string|max:120',
            'bed_ids' => 'nullable|array',
            'bed_ids.*' => 'uuid|exists:beds,id',
            'status' => 'sometimes|in:active,scheduled,offline,maintenance',
            'capacity_lph' => 'nullable|numeric|min:0|max:100000',
            'flow_rate_lph' => 'nullable|numeric|min:0|max:100000',
            'pressure_psi' => 'nullable|numeric|min:0|max:10000',
            'power_level' => 'nullable|numeric|min:0|max:100',
            'temperature_celsius' => 'nullable|numeric|min:-50|max:100',
            'coverage_area' => 'nullable|numeric|min:0|max:100000',
            'water_usage_today_liters' => 'nullable|numeric|min:0|max:1000000',
            'last_maintenance_at' => 'nullable|date',
            'maintenance_notes' => 'nullable|string|max:1000',
        ];
    }

    private function scheduleRules(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';

        return [
            'farm_id' => $partial ? 'sometimes|uuid|exists:farms,id' : 'nullable|uuid|exists:farms,id',
            'irrigation_system_id' => 'nullable|uuid|exists:irrigation_systems,id',
            'name' => "{$required}|string|max:120",
            'start_time' => "{$required}|date_format:H:i",
            'duration_minutes' => 'sometimes|integer|min:1|max:1440',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'in:mon,tue,wed,thu,fri,sat,sun',
            'status' => 'sometimes|in:active,paused,completed',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'water_amount_liters' => 'nullable|numeric|min:0|max:1000000',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    private function farmId(Request $request): string
    {
        return $request->input('farm_id')
            ?? $request->header('X-Tenant-ID')
            ?? abort(response()->json([
                'success' => false,
                'message' => 'Farm context is required',
            ], 422));
    }

    private function abortIfWrongFarm(Request $request, string $farmId): void
    {
        abort_if($farmId !== $this->farmId($request), 404, 'Resource not found for active farm');
    }

    private function validationFailed($validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422);
    }

    private function syncSystemBeds(IrrigationSystem $system): void
    {
        $bedIds = $system->bed_ids ?? [];
        if (!$bedIds) {
            return;
        }

        Bed::where('farm_id', $system->farm_id)
            ->whereIn('id', $bedIds)
            ->update([
                'has_irrigation' => $system->status === 'active',
                'irrigation_type' => $system->type,
            ]);
    }

    private function stats($systems, $schedules): array
    {
        $activeSystems = $systems->where('status', 'active');
        $nextSchedule = $schedules
            ->where('status', 'active')
            ->sortBy('next_run_at')
            ->first();

        return [
            'water_usage_today' => round((float) $systems->sum('water_usage_today_liters'), 2),
            'active_zones' => $activeSystems->count(),
            'next_schedule' => $nextSchedule?->next_run_at?->toISOString(),
            'system_health' => $systems->count()
                ? round(($systems->whereNotIn('status', ['offline', 'maintenance'])->count() / $systems->count()) * 100)
                : 0,
        ];
    }

    private function nextRunAt(array $data): ?string
    {
        if (($data['status'] ?? 'active') !== 'active' || empty($data['start_time'])) {
            return null;
        }

        $time = $data['start_time'];
        $next = now()->setTimeFromTimeString(strlen($time) === 5 ? "{$time}:00" : $time);

        if ($next->isPast()) {
            $next->addDay();
        }

        return $next->toDateTimeString();
    }
}
