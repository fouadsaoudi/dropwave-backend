<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

class AdminUserManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $adminUser;
    private Role $adminRole;
    private Role $agentRole;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::firstOrCreate(['name' => 'admin']);
        $this->agentRole = Role::firstOrCreate(['name' => 'agent']);
        Role::firstOrCreate(['name' => 'manager']);
        
        $this->tenant = Tenant::create([
            'name' => 'Admin Test Tenant',
            'slug' => 'admin-test-tenant',
            'email' => 'admin-test-tenant@example.com',
        ]);

        $this->adminUser = User::create([
            'tenant_id' => null, // Super admin has no tenant
            'name' => 'Dropwave Admin Test User',
            'email' => 'admin-test-auth@dropwave.app',
            'password' => bcrypt('password'),
            'role_id' => $this->adminRole->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_list_users()
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'tenant_id',
                    'role_id',
                    'name',
                    'email',
                    'is_active',
                    'created_at',
                    'role',
                    'tenant'
                ]
            ]);
    }

    public function test_admin_can_list_roles()
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/roles');

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'admin'])
            ->assertJsonFragment(['name' => 'agent'])
            ->assertJsonFragment(['name' => 'manager']);
    }

    public function test_admin_can_create_user_with_tenant()
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/admin/users', [
                'tenant_id' => $this->tenant->id,
                'name' => 'New Workspace Agent',
                'email' => 'new-agent@example.com',
                'password' => 'password123',
                'role_id' => $this->agentRole->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.name', 'New Workspace Agent')
            ->assertJsonPath('user.tenant_id', $this->tenant->id);

        $this->assertDatabaseHas('users', [
            'email' => 'new-agent@example.com',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_admin_can_create_user_without_tenant()
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/admin/users', [
                'tenant_id' => null,
                'name' => 'Another Admin',
                'email' => 'another-admin@example.com',
                'password' => 'password123',
                'role_id' => $this->adminRole->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.name', 'Another Admin')
            ->assertJsonPath('user.tenant_id', null);

        $this->assertDatabaseHas('users', [
            'email' => 'another-admin@example.com',
            'tenant_id' => null,
        ]);
    }

    public function test_admin_can_update_user_including_password_reset()
    {
        $targetUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Original Name',
            'email' => 'original@example.com',
            'password' => bcrypt('oldpassword'),
            'role_id' => $this->agentRole->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson("/api/admin/users/{$targetUser->id}", [
                'tenant_id' => null, // Convert to system admin
                'name' => 'Updated Name',
                'role_id' => $this->adminRole->id,
                'is_active' => false,
                'password' => 'newpassword123', // Reset password
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'name' => 'Updated Name',
            'role_id' => $this->adminRole->id,
            'tenant_id' => null,
            'is_active' => false,
        ]);

        // Verify password hash updated
        $updatedUser = User::find($targetUser->id);
        $this->assertTrue(Hash::check('newpassword123', $updatedUser->password));
    }

    public function test_admin_can_delete_user()
    {
        $targetUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Delete Me',
            'email' => 'delete-me@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/admin/users/{$targetUser->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_cannot_delete_self()
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/admin/users/{$this->adminUser->id}");

        $response->assertStatus(400)
            ->assertJsonFragment(['message' => 'Deletions denied: you cannot deactivate your active session profile.']);

        $this->assertDatabaseHas('users', ['id' => $this->adminUser->id]);
    }

    public function test_non_admin_cannot_access_admin_endpoints()
    {
        $agentUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Agent User',
            'email' => 'agent-test@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
        ]);

        $response = $this->actingAs($agentUser, 'sanctum')
            ->getJson('/api/admin/users');

        $response->assertStatus(403);
    }
}
