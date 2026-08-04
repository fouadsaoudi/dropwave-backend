<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Models\WabaChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ManagerRoleAccessTest extends TestCase
{
    use DatabaseTransactions;

    private User $managerUser;
    private Role $managerRole;
    private Role $agentRole;
    private Role $adminRole;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->managerRole = Role::firstOrCreate(['name' => 'manager']);
        $this->agentRole = Role::firstOrCreate(['name' => 'agent']);
        $this->adminRole = Role::firstOrCreate(['name' => 'admin']);
        
        $this->tenant = Tenant::create([
            'name' => 'Manager Test Tenant',
            'slug' => 'manager-test-tenant',
            'email' => 'manager-test-tenant@example.com',
        ]);

        $this->otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'email' => 'other-tenant@example.com',
        ]);

        $this->managerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Click & Pick Manager Test User',
            'email' => 'manager-test-auth@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->managerRole->id,
            'is_active' => true,
        ]);
    }

    public function test_manager_can_access_agent_workspace_pages()
    {
        // Manager should be able to view their workspace channels
        WabaChannel::create([
            'tenant_id' => $this->tenant->id,
            'display_name' => 'Waba Test Channels',
            'phone_number' => '+15551234567',
            'phone_number_id' => '123456',
            'waba_id' => 'waba_test_123',
            'access_token' => encrypt('token'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson('/api/channels', [
                'X-Tenant-Id' => $this->tenant->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'display_name',
                    'phone_number',
                    'phone_number_id',
                    'quality_rating',
                    'is_primary',
                    'connected_at',
                ]
            ]);
    }

    public function test_manager_cannot_access_admin_only_endpoints()
    {
        // Manager should be blocked on administrative platform endpoints
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson('/api/admin/overview');

        $response->assertStatus(403);
    }

    public function test_manager_can_list_only_tenant_users()
    {
        // Create user in manager's tenant
        $tenantUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tenant Agent',
            'email' => 'tenant-agent@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
        ]);

        // Create user in another tenant
        $otherUser = User::create([
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Other Tenant Agent',
            'email' => 'other-agent@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
        ]);

        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonFragment(['email' => 'tenant-agent@example.com'])
            ->assertJsonMissing(['email' => 'other-agent@example.com']);
    }

    public function test_manager_can_create_user_in_tenant()
    {
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'New Agent',
                'email' => 'new-agent@example.com',
                'password' => 'password123',
                'role_id' => $this->agentRole->id,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'new-agent@example.com',
            'tenant_id' => $this->tenant->id, // Tenant forced to manager's tenant
        ]);
    }

    public function test_manager_cannot_assign_admin_role()
    {
        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'Fake Admin',
                'email' => 'fake-admin@example.com',
                'password' => 'password123',
                'role_id' => $this->adminRole->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_manager_can_update_user_in_tenant()
    {
        $targetUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
        ]);

        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/admin/users/{$targetUser->id}", [
                'name' => 'New Name',
                'role_id' => $this->managerRole->id,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'name' => 'New Name',
            'role_id' => $this->managerRole->id,
        ]);
    }

    public function test_manager_cannot_update_user_outside_tenant()
    {
        $targetUser = User::create([
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Other Old Name',
            'email' => 'other-old@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
        ]);

        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->putJson("/api/admin/users/{$targetUser->id}", [
                'name' => 'Hacked Name',
            ]);

        $response->assertStatus(404);
    }

    public function test_manager_can_deactivate_user_in_tenant()
    {
        $targetUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deactivate Me',
            'email' => 'deac@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->deleteJson("/api/admin/users/{$targetUser->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'is_active' => false,
        ]);
    }

    public function test_manager_cannot_deactivate_user_outside_tenant()
    {
        $targetUser = User::create([
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Other Deactivate Me',
            'email' => 'other-deac@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->managerUser, 'sanctum')
            ->deleteJson("/api/admin/users/{$targetUser->id}");

        $response->assertStatus(404);
    }
}
