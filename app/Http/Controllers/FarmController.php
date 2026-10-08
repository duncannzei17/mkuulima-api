<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\User;
use App\Models\FarmUser;
use App\Models\TenantRegistry;
use App\Services\GeocodingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FarmController extends Controller
{
    protected $geocodingService;

    public function __construct(GeocodingService $geocodingService)
    {
        $this->geocodingService = $geocodingService;
    }

    /**
     * Get all farms for authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Get owned farms
            $ownedFarms = $user->ownedFarms()->with('tenantRegistry')->get();

            // Get farms as worker/manager
            $workerFarms = $user->farms()->with('tenantRegistry')->get();

            // Combine and format
            $allFarms = $ownedFarms->concat($workerFarms)->map(function ($farm) use ($user) {
                return [
                    'id' => $farm->id,
                    'name' => $farm->name,
                    'location' => $farm->location,
                    'latitude' => $farm->latitude,
                    'longitude' => $farm->longitude,
                    'timezone' => $farm->timezone,
                    'size' => $farm->size,
                    'size_unit' => $farm->size_unit,
                    'type' => $farm->type,
                    'ownership' => $farm->ownership,
                    'starting_year' => $farm->starting_year,
                    'status' => $farm->status,
                    'tenant_status' => $farm->tenantRegistry?->status ?? 'not_provisioned',
                    'role' => $user->getRoleOnFarm($farm->id),
                    'permissions' => $user->getPermissionsOnFarm($farm->id),
                    'created_at' => $farm->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'farms' => $allFarms,
                    'total' => $allFarms->count()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve farms',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Create a new farm
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Validate farm data according to PRD
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'location' => 'required|array',
                'location.county' => 'required|string|max:100',
                'location.ward' => 'required|string|max:100', 
                'location.village' => 'required|string|max:100',
                'size' => 'required|numeric|min:0.1|max:10000',
                'size_unit' => 'sometimes|string|in:acres,hectares,square_meters',
                'type' => 'required|string|in:vegetables,mixed,dairy,poultry,crops,livestock,fruits,herbs,flowers,other',
                'ownership' => 'required|string|in:owned,leased,shared,other',
                'starting_year' => 'required|integer|min:1900|max:' . (date('Y') + 1),
                'description' => 'sometimes|nullable|string|max:1000',
                'coordinates' => 'sometimes|array',
                'coordinates.latitude' => 'sometimes|numeric|between:-90,90',
                'coordinates.longitude' => 'sometimes|numeric|between:-180,180',
                'soil_type' => 'sometimes|nullable|string|max:100',
                'climate_zone' => 'sometimes|nullable|string|max:100',
                'water_source' => 'sometimes|nullable|string|max:100',
                'organic_certified' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Start database transaction for atomicity
            DB::beginTransaction();

            // Process coordinates from location data
            $coordinates = $this->processCoordinates($request);
            
            // Build location string for geocoding if coordinates not provided
            $locationString = $this->buildLocationString($request->location);

            // Create farm record in master DB
            $farm = Farm::create([
                'user_id' => $user->id,
                'name' => $request->name,
                'location' => $locationString, // Store as string for display
                'size' => $request->size,
                'size_unit' => $request->size_unit ?? 'acres',
                'type' => $request->type,
                'ownership' => $request->ownership,
                'starting_year' => $request->starting_year,
                'description' => $request->description,
                'latitude' => $coordinates['latitude'] ?? null,
                'longitude' => $coordinates['longitude'] ?? null,
                'timezone' => $coordinates['timezone'] ?? 'Africa/Nairobi',
                'soil_type' => $request->soil_type,
                'climate_zone' => $request->climate_zone,
                'water_source' => $request->water_source,
                'organic_certified' => $request->organic_certified ?? false,
            ]);

            // Create tenant registry entry
            $tenantRegistry = TenantRegistry::createForFarm(
                $farm->id,
                $farm->tenant_schema_name,
                $user->id
            );

            DB::commit();

            if (!$tenantRegistry->provisionTenant()) {
                $failureReason = $tenantRegistry->failure_reason;
                $this->cleanupFailedProvisioning($farm, $tenantRegistry);

                Log::error('Farm tenant provisioning failed and was compensated', [
                    'farm_id' => $farm->id,
                    'schema_name' => $farm->tenant_schema_name,
                    'reason' => $failureReason,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Farm creation failed during tenant provisioning. No farm was created.',
                    'error' => config('app.debug') ? $failureReason : null,
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Farm created successfully. Tenant provisioned.',
                'data' => [
                    'farm' => [
                        'id' => $farm->id,
                        'name' => $farm->name,
                        'location' => $farm->location,
                        'size' => $farm->size,
                        'size_unit' => $farm->size_unit,
                        'type' => $farm->type,
                        'ownership' => $farm->ownership,
                        'starting_year' => $farm->starting_year,
                        'status' => $farm->status,
                        'tenant_status' => $tenantRegistry->status,
                        'tenant_schema' => $farm->tenant_schema_name,
                        'role' => 'owner',
                        'permissions' => ['*'],
                        'created_at' => $farm->created_at,
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Farm creation failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function cleanupFailedProvisioning(Farm $farm, TenantRegistry $tenantRegistry): void
    {
        if (!$tenantRegistry->dropTenant()) {
            Log::critical('Unable to drop a failed tenant during farm creation compensation', [
                'farm_id' => $farm->id,
                'schema_name' => $tenantRegistry->schema_name,
            ]);
        }

        if ($farm->exists) {
            $farm->forceDelete();
        }
    }

    /**
     * Switch active farm (set tenant context)
     */
    public function switchFarm(Request $request, string $farmId): JsonResponse
    {
        try {
            $user = $request->user();

            // Check access as per PRD requirements
            if (!$user->hasAccessToFarm($farmId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. You do not have permission to access this farm.'
                ], 403);
            }

            $farm = Farm::with('tenantRegistry')->findOrFail($farmId);

            // Check tenant status
            if ($farm->tenantRegistry?->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Farm tenant is not ready. Please wait for provisioning to complete.',
                    'tenant_status' => $farm->tenantRegistry?->status ?? 'not_provisioned'
                ], 423);
            }

            return response()->json([
                'success' => true,
                'message' => 'Farm switched successfully',
                'data' => [
                    'active_farm' => [
                        'id' => $farm->id,
                        'name' => $farm->name,
                        'tenant_schema' => $farm->tenant_schema_name,
                        'role' => $user->getRoleOnFarm($farmId),
                        'permissions' => $user->getPermissionsOnFarm($farmId),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Farm switch failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * List farm members who can be assigned operational tasks.
     */
    public function getWorkers(Request $request, string $farmId): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToFarm($farmId)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. You do not have permission to view this farm team.',
            ], 403);
        }

        $farm = Farm::with('owner')->findOrFail($farmId);
        $members = $farm->users()->get()
            ->map(fn (User $member) => [
                'id' => $member->id,
                'farm_id' => $farmId,
                'name' => $member->name,
                'phone' => $member->phone,
                'role' => $member->pivot->role,
                'permissions' => $member->pivot->permissions ?? [],
                'status' => $member->pivot->status,
                'invited_at' => $member->pivot->invitation_sent_at ?? $member->pivot->created_at,
                'joined_at' => $member->pivot->invitation_accepted_at ?? $member->pivot->created_at,
                'invited_by' => $member->pivot->invited_by,
            ]);

        $workers = collect([[
            'id' => $farm->owner->id,
            'farm_id' => $farmId,
            'name' => $farm->owner->name,
            'phone' => $farm->owner->phone,
            'role' => 'owner',
            'permissions' => ['*'],
            'status' => 'active',
            'invited_at' => $farm->created_at,
            'joined_at' => $farm->created_at,
            'invited_by' => $farm->owner->id,
        ]])->concat($members)->unique('id')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'workers' => $workers,
                'total' => $workers->count(),
            ],
        ]);
    }

    /**
     * Add a worker to the farm
     */
    public function addWorker(Request $request, string $farmId): JsonResponse
    {
        try {
            $user = $request->user();

            if ($request->filled('phone')) {
                $request->merge(['phone' => $this->normalizePhoneNumber((string) $request->phone)]);
            }

            // Check permissions
            if (!$user->ownsFarm($farmId) && !$user->hasPermissionOnFarm($farmId, 'manage_workers')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. You do not have permission to add workers.'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'phone' => ['required', 'string', 'regex:/^(\+254|0)[7-9]\d{8}$/'],
                'role' => 'required|string|in:manager,worker,observer',
                'permissions' => 'sometimes|array',
                'hourly_rate' => 'sometimes|nullable|numeric|min:0',
                'employment_type' => 'sometimes|string|in:full_time,part_time,casual,contract',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $phone = $request->phone;

            // Membership grants access to an existing account. Account onboarding and
            // message delivery are separate workflows and must not be fabricated here.
            $worker = User::findByPhone($phone);
            if (!$worker) {
                return response()->json([
                    'success' => false,
                    'message' => 'No registered account was found for this phone number.',
                    'errors' => ['phone' => ['Ask the worker to register before adding them to the farm.']],
                ], 422);
            }

            $existingMembership = FarmUser::withTrashed()
                ->where('farm_id', $farmId)
                ->where('user_id', $worker->id)
                ->first();

            if ($existingMembership && !$existingMembership->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This person is already a member of this farm.'
                ], 409);
            }

            $membershipData = [
                'role' => $request->role,
                'permissions' => $request->permissions ?? User::getDefaultPermissions($request->role),
                'status' => 'active',
                'invited_by' => $user->id,
                'invitation_sent_at' => now(),
                'invitation_accepted_at' => now(),
            ];

            if ($existingMembership) {
                $existingMembership->restore();
                $existingMembership->update($membershipData);
                $farmUser = $existingMembership;
            } else {
                $farmUser = FarmUser::create([
                    'user_id' => $worker->id,
                    'farm_id' => $farmId,
                    ...$membershipData,
                ]);
            }

            if ($request->hourly_rate) {
                $farmUser->hourly_rate = $request->hourly_rate;
                $farmUser->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Worker added successfully',
                'data' => [
                    'worker' => [
                        'id' => $worker->id,
                        'name' => $worker->name,
                        'phone' => $worker->phone,
                        'role' => $farmUser->role,
                        'permissions' => $farmUser->permissions,
                        'status' => $farmUser->status,
                        'employment_type' => $farmUser->employment_type,
                        'hourly_rate' => $farmUser->hourly_rate,
                        'added_at' => $farmUser->created_at,
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add worker',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update a farm member's role and permissions.
     */
    public function updateWorker(Request $request, string $farmId, string $userId): JsonResponse
    {
        $actor = $request->user();

        if (!$actor->ownsFarm($farmId) && !$actor->hasPermissionOnFarm($farmId, 'manage_workers')) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. You do not have permission to update workers.',
            ], 403);
        }

        $farm = Farm::findOrFail($farmId);
        if ($farm->user_id === $userId) {
            return response()->json([
                'success' => false,
                'message' => 'The farm owner role cannot be changed.',
            ], 422);
        }

        $validated = $request->validate([
            'role' => 'sometimes|string|in:manager,worker,observer',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|max:100',
            'status' => 'sometimes|string|in:active,inactive,suspended',
        ]);

        $membership = FarmUser::query()
            ->where('farm_id', $farmId)
            ->where('user_id', $userId)
            ->firstOrFail();
        $membership->update($validated);
        $member = User::findOrFail($userId);

        return response()->json([
            'success' => true,
            'message' => 'Worker access updated successfully',
            'data' => [
                'worker' => [
                    'id' => $member->id,
                    'farm_id' => $farmId,
                    'name' => $member->name,
                    'phone' => $member->phone,
                    'role' => $membership->role,
                    'permissions' => $membership->permissions ?? [],
                    'status' => $membership->status,
                    'invited_at' => $membership->invitation_sent_at ?? $membership->created_at,
                    'joined_at' => $membership->invitation_accepted_at ?? $membership->created_at,
                    'invited_by' => $membership->invited_by,
                ],
            ],
        ]);
    }

    /**
     * Revoke a member's farm access while preserving operational history.
     */
    public function removeWorker(Request $request, string $farmId, string $userId): JsonResponse
    {
        $actor = $request->user();

        if (!$actor->ownsFarm($farmId) && !$actor->hasPermissionOnFarm($farmId, 'manage_workers')) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. You do not have permission to remove workers.',
            ], 403);
        }

        $farm = Farm::findOrFail($farmId);
        if ($farm->user_id === $userId || $actor->id === $userId) {
            return response()->json([
                'success' => false,
                'message' => 'The farm owner or current user cannot be removed.',
            ], 422);
        }

        $membership = FarmUser::query()
            ->where('farm_id', $farmId)
            ->where('user_id', $userId)
            ->firstOrFail();
        $membership->delete();

        return response()->json([
            'success' => true,
            'message' => 'Worker access removed successfully',
        ]);
    }

    /**
     * Get farm details
     */
    public function show(Request $request, string $farmId): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user->hasAccessToFarm($farmId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied.'
                ], 403);
            }

            $farm = Farm::with(['owner', 'users', 'tenantRegistry'])->findOrFail($farmId);

            return response()->json([
                'success' => true,
                'data' => [
                    'farm' => [
                        'id' => $farm->id,
                        'name' => $farm->name,
                        'location' => $farm->location,
                        'size' => $farm->size,
                        'size_unit' => $farm->size_unit,
                        'type' => $farm->type,
                        'ownership' => $farm->ownership,
                        'starting_year' => $farm->starting_year,
                        'status' => $farm->status,
                        'tenant_status' => $farm->tenantRegistry?->status,
                        'role' => $user->getRoleOnFarm($farmId),
                        'permissions' => $user->getPermissionsOnFarm($farmId),
                        'owner' => [
                            'id' => $farm->owner->id,
                            'name' => $farm->owner->name,
                            'phone' => $farm->owner->phone,
                        ],
                        'workers_count' => $farm->users()->count(),
                        'created_at' => $farm->created_at,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve farm details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update farm details
     */
    public function update(Request $request, string $farmId): JsonResponse
    {
        try {
            $user = $request->user();

            // Check permissions - only owners and managers can update farm details
            if (!$user->ownsFarm($farmId) && !$user->hasPermissionOnFarm($farmId, 'manage_farm')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. You do not have permission to update this farm.'
                ], 403);
            }

            $farm = Farm::findOrFail($farmId);

            // Validate farm data
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'location' => 'sometimes|array',
                'location.county' => 'sometimes|string|max:100',
                'location.ward' => 'sometimes|string|max:100', 
                'location.village' => 'sometimes|string|max:100',
                'size' => 'sometimes|numeric|min:0.1|max:10000',
                'size_unit' => 'sometimes|string|in:acres,hectares,square_meters',
                'type' => 'sometimes|string|in:vegetables,mixed,dairy,poultry,crops,livestock,fruits,herbs,flowers,other',
                'ownership' => 'sometimes|string|in:owned,leased,shared,other',
                'starting_year' => 'sometimes|integer|min:1900|max:' . (date('Y') + 1),
                'description' => 'sometimes|nullable|string|max:1000',
                'coordinates' => 'sometimes|array',
                'coordinates.latitude' => 'sometimes|numeric|between:-90,90',
                'coordinates.longitude' => 'sometimes|numeric|between:-180,180',
                'soil_type' => 'sometimes|nullable|string|max:100',
                'climate_zone' => 'sometimes|nullable|string|max:100',
                'water_source' => 'sometimes|nullable|string|max:100',
                'organic_certified' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Update farm with provided data
            $updateData = $request->only([
                'name', 'location', 'size', 'size_unit', 'type', 'ownership', 
                'starting_year', 'description', 'coordinates', 'soil_type', 
                'climate_zone', 'water_source', 'organic_certified'
            ]);

            $farm->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Farm updated successfully',
                'data' => [
                    'farm' => [
                        'id' => $farm->id,
                        'name' => $farm->name,
                        'location' => $farm->location,
                        'size' => $farm->size,
                        'size_unit' => $farm->size_unit,
                        'type' => $farm->type,
                        'ownership' => $farm->ownership,
                        'starting_year' => $farm->starting_year,
                        'status' => $farm->status,
                        'role' => $user->getRoleOnFarm($farmId),
                        'permissions' => $user->getPermissionsOnFarm($farmId),
                        'updated_at' => $farm->updated_at,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update farm',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Archive/deactivate a farm
     */
    public function archive(Request $request, string $farmId): JsonResponse
    {
        try {
            $user = $request->user();

            // Only owners can archive farms
            if (!$user->ownsFarm($farmId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Only farm owners can archive farms.'
                ], 403);
            }

            $farm = Farm::with('tenantRegistry')->findOrFail($farmId);

            DB::beginTransaction();

            $farm->status = 'archived';
            $farm->save();

            // Archive tenant registry
            if ($farm->tenantRegistry) {
                $farm->tenantRegistry->archive();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Farm archived successfully. It is now in read-only mode.',
                'data' => [
                    'farm' => [
                        'id' => $farm->id,
                        'name' => $farm->name,
                        'status' => $farm->status,
                        'archived_at' => now(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'success' => false,
                'message' => 'Farm archiving failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function normalizePhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
        
        if (preg_match('/^0([7-9]\d{8})$/', $phone, $matches)) {
            return '+254' . $matches[1];
        }
        
        if (preg_match('/^254([7-9]\d{8})$/', $phone, $matches)) {
            return '+254' . $matches[1];
        }
        
        return $phone;
    }

    /**
     * Process coordinates from request data
     */
    private function processCoordinates(Request $request): array
    {
        $coordinates = [];

        // First, check if coordinates were directly provided
        if ($request->has('coordinates')) {
            $parsedCoords = $this->geocodingService->parseCoordinatesInput($request->coordinates);
            if ($parsedCoords) {
                $coordinates = $parsedCoords;
                Log::info('Using provided coordinates', $coordinates);
            }
        }

        // If no valid coordinates provided, try to geocode from location
        if (empty($coordinates) && $request->has('location')) {
            $locationString = $this->buildLocationString($request->location);
            $geocoded = $this->geocodingService->getCoordinatesFromLocation($locationString);
            
            if ($geocoded) {
                $coordinates['latitude'] = $geocoded['latitude'];
                $coordinates['longitude'] = $geocoded['longitude'];
                Log::info('Geocoded coordinates from location', [
                    'location' => $locationString,
                    'coordinates' => $coordinates
                ]);
            }
        }

        // Get timezone if we have coordinates
        if (!empty($coordinates) && isset($coordinates['latitude'], $coordinates['longitude'])) {
            $timezone = $this->geocodingService->getTimezoneFromCoordinates(
                $coordinates['latitude'], 
                $coordinates['longitude']
            );
            
            if ($timezone) {
                $coordinates['timezone'] = $timezone;
            }
        }

        return $coordinates;
    }

    /**
     * Build location string from location array
     */
    private function buildLocationString(array $location): string
    {
        $parts = [];
        
        // Build from specific to general: Village, Ward, County
        if (isset($location['village']) && !empty($location['village'])) {
            $parts[] = $location['village'];
        }
        
        if (isset($location['ward']) && !empty($location['ward'])) {
            $parts[] = $location['ward'];
        }
        
        if (isset($location['county']) && !empty($location['county'])) {
            $parts[] = $location['county'];
        }
        
        // Add Kenya if not already specified
        $locationStr = implode(', ', $parts);
        if (!str_contains(strtolower($locationStr), 'kenya')) {
            $locationStr .= ', Kenya';
        }
        
        return $locationStr;
    }
}
