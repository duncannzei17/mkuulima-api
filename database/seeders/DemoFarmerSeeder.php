<?php

namespace Database\Seeders;

use App\Models\Farm;
use App\Models\FarmUser;
use App\Models\CropCycle;
use App\Models\CropTask;
use App\Models\TenantRegistry;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoFarmerSeeder extends Seeder
{
    /**
     * Seed a demo farmer with credentials and a farm record.
     */
    public function run(): void
    {
        $normalizedPhone = '+254700123456';

        $farmer = User::updateOrCreate(
            ['phone' => $normalizedPhone],
            [
                'phone' => $normalizedPhone,
                'name' => 'Test Farmer',
                'email' => 'demo.farmer@farmos.test',
                'county' => 'Kiambu',
                'password' => 'Password123!',
                'language' => 'en',
                'timezone' => 'Africa/Nairobi',
                'status' => 'active',
                'preferences' => [
                    'notifications' => [
                        'sms' => true,
                        'email' => false,
                    ],
                ],
            ]
        );

        $farm = Farm::updateOrCreate(
            [
                'tenant_schema_name' => 'farm_test_current',
            ],
            [
                'user_id' => $farmer->id,
                'name' => 'Current Schema Test Farm',
                'location' => [
                    'county' => 'Kiambu',
                    'sub_county' => 'Juja',
                    'ward' => 'Witeithie',
                    'village' => 'Magomano',
                ],
                'size' => 12.5,
                'size_unit' => 'acres',
                'type' => 'mixed',
                'ownership' => 'owned',
                'starting_year' => 2018,
                'subscription_tier' => 'pro',
                'status' => 'active',
                'coordinates' => [
                    'lat' => -1.122,
                    'lng' => 37.005,
                ],
                'water_source' => 'Borehole',
                'description' => 'Clean local test farm seeded against the current tenant schema.',
                'settings' => [
                    'currency' => 'KES',
                    'measurement_system' => 'metric',
                ],
            ]
        );

        FarmUser::updateOrCreate(
            [
                'user_id' => $farmer->id,
                'farm_id' => $farm->id,
            ],
            [
                'role' => 'owner',
                'permissions' => User::getDefaultPermissions('owner'),
                'status' => 'active',
                'hire_date' => now()->toDateString(),
                'employment_type' => 'full_time',
            ]
        );

        $tenant = TenantRegistry::updateOrCreate(
            [
                'farm_id' => $farm->id,
            ],
            [
                'schema_name' => $farm->tenant_schema_name,
                'tenancy_type' => 'schema',
                'status' => 'provisioning',
                'settings' => [
                    'region' => 'KE',
                ],
                'provisioned_by' => $farmer->id,
            ]
        );

        if (!$tenant->provisionTenant()) {
            throw new RuntimeException(
                'Test tenant provisioning failed: ' . ($tenant->fresh()->failure_reason ?? 'unknown error')
            );
        }

        try {
            $tenant->switchToTenant();

            $crop = CropCycle::create([
                'farm_id' => $farm->id,
                'crop_name' => 'Onions',
                'variety' => 'Red onions',
                'season_name' => 'Test Season 2026',
                'start_date' => now()->toDateString(),
                'expected_harvest_date' => now()->addDays(90)->toDateString(),
                'season_status' => 'growing',
                'land_area' => 4,
                'land_area_unit' => 'acres',
                'area_unit' => 'acres',
                'has_nursery' => false,
                'seed_quantity' => 500,
                'seed_unit' => 'grams',
                'notes' => 'Seeded with the current crop cycle schema.',
                'bed_ids' => [],
                'planned_inputs' => [],
                'planned_tasks' => [],
                'expected_yield' => 12000,
                'yield_unit' => 'kg',
                'health_status' => 'good',
            ]);

            CropTask::create([
                'crop_cycle_id' => $crop->id,
                'task_name' => 'Initial watering',
                'task_type' => 'watering',
                'description' => 'Verify task creation against the current schema.',
                'scheduled_date' => now()->toDateString(),
                'scheduled_time' => '09:35',
                'status' => 'scheduled',
                'priority' => 'medium',
                'estimated_duration' => 30,
            ]);
        } finally {
            DB::statement('SET search_path TO public');
        }

        $this->command?->info('Test farm seeded with the current tenant schema.');
        $this->command?->info('Login phone: +254700123456');
        $this->command?->info('Login password: Password123!');
    }
}
