<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TenantMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class FarmProvisioningCompensationTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_tenant_provisioning_leaves_no_partial_farm_records(): void
    {
        $user = User::create([
            'name' => 'Provisioning Owner',
            'phone' => '+254700000020',
            'password' => 'password123',
        ]);
        Sanctum::actingAs($user);

        $migrationService = $this->mock(TenantMigrationService::class);
        $migrationService->shouldReceive('migrate')
            ->once()
            ->andThrow(new RuntimeException('Forced tenant migration failure'));

        $this->postJson('/api/farms', [
            'name' => 'Compensated Farm',
            'location' => [
                'county' => 'Kiambu',
                'ward' => 'Juja',
                'village' => 'Witeithie',
            ],
            'size' => 2.5,
            'size_unit' => 'acres',
            'type' => 'mixed',
            'ownership' => 'owned',
            'starting_year' => 2025,
        ])->assertInternalServerError()
            ->assertJsonPath('message', 'Farm creation failed during tenant provisioning. No farm was created.');

        $this->assertDatabaseMissing('farms', ['name' => 'Compensated Farm']);
        $this->assertDatabaseCount('tenant_registry', 0);
    }
}
