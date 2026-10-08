<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\FarmUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FarmWorkerManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $worker;
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::create([
            'name' => 'Farm Owner',
            'phone' => '+254700000001',
            'password' => 'secret-password',
        ]);
        $this->worker = User::create([
            'name' => 'Farm Worker',
            'phone' => '+254700000002',
            'password' => 'secret-password',
        ]);
        $this->farm = Farm::create([
            'user_id' => $this->owner->id,
            'name' => 'Production Farm',
            'location' => ['county' => 'Nakuru', 'ward' => 'Naivasha', 'village' => 'Karagita'],
            'size' => 10,
            'type' => 'mixed',
            'ownership' => 'owned',
            'starting_year' => 2024,
        ]);

        Sanctum::actingAs($this->owner);
    }

    public function test_owner_can_add_and_list_a_registered_worker(): void
    {
        $this->postJson("/api/farms/{$this->farm->id}/workers", [
            'name' => 'Farm Worker',
            'phone' => '0700000002',
            'role' => 'worker',
            'permissions' => ['view_dashboard', 'add_labour'],
        ])->assertCreated()
            ->assertJsonPath('data.worker.id', $this->worker->id)
            ->assertJsonPath('data.worker.status', 'active');

        $this->assertDatabaseHas('farm_user', [
            'farm_id' => $this->farm->id,
            'user_id' => $this->worker->id,
            'role' => 'worker',
            'status' => 'active',
        ]);

        $this->getJson("/api/farms/{$this->farm->id}/workers")
            ->assertOk()
            ->assertJsonCount(2, 'data.workers')
            ->assertJsonFragment([
                'id' => $this->worker->id,
                'role' => 'worker',
                'status' => 'active',
            ]);
    }

    public function test_unregistered_phone_is_rejected_without_creating_an_account(): void
    {
        $this->postJson("/api/farms/{$this->farm->id}/workers", [
            'name' => 'Unknown Worker',
            'phone' => '0700000099',
            'role' => 'worker',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'No registered account was found for this phone number.');

        $this->assertDatabaseMissing('users', ['phone' => '+254700000099']);
    }

    public function test_owner_can_update_role_and_permissions_then_revoke_access(): void
    {
        $membership = FarmUser::createForUser(
            $this->worker->id,
            $this->farm->id,
            'worker',
            ['view_dashboard'],
            $this->owner->id,
        );

        $this->patchJson("/api/farms/{$this->farm->id}/workers/{$this->worker->id}", [
            'role' => 'manager',
            'permissions' => ['view_dashboard', 'manage_workers'],
        ])->assertOk()
            ->assertJsonPath('data.worker.role', 'manager')
            ->assertJsonPath('data.worker.permissions.1', 'manage_workers');

        $this->deleteJson("/api/farms/{$this->farm->id}/workers/{$this->worker->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Worker access removed successfully');

        $this->assertSoftDeleted('farm_user', ['id' => $membership->id]);
        $this->assertDatabaseHas('users', ['id' => $this->worker->id]);

        $this->getJson("/api/farms/{$this->farm->id}/workers")
            ->assertOk()
            ->assertJsonCount(1, 'data.workers')
            ->assertJsonMissing(['id' => $this->worker->id]);

        Sanctum::actingAs($this->worker);
        $this->getJson("/api/farms/{$this->farm->id}")->assertForbidden();
    }

    public function test_worker_without_management_permission_cannot_modify_membership(): void
    {
        FarmUser::createForUser(
            $this->worker->id,
            $this->farm->id,
            'worker',
            ['view_dashboard'],
            $this->owner->id,
        );
        Sanctum::actingAs($this->worker);

        $this->patchJson("/api/farms/{$this->farm->id}/workers/{$this->owner->id}", [
            'role' => 'manager',
        ])->assertForbidden();

        $this->deleteJson("/api/farms/{$this->farm->id}/workers/{$this->owner->id}")
            ->assertForbidden();
    }
}
