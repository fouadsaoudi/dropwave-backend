<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Models\WabaChannel;
use App\Models\TenantBillingSnapshot;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class AdminOverviewTest extends TestCase
{
    use DatabaseTransactions;

    private User $adminUser;
    private User $agentUser;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $agentRole = Role::firstOrCreate(['name' => 'agent']);

        $this->tenant = Tenant::create([
            'name' => 'Overview Test Tenant',
            'slug' => 'overview-test-tenant-' . uniqid(),
            'email' => 'overview-test-' . uniqid() . '@example.com',
        ]);

        $this->adminUser = User::create([
            'tenant_id' => null,
            'name' => 'Admin Test User',
            'email' => 'admin-overview-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $this->agentUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Agent Test User',
            'email' => 'agent-overview-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role_id' => $agentRole->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_fetch_overview_fast_with_correct_structure()
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/admin/overview');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'tenants_count',
                'channels_count',
                'healthy_channels_count',
                'problematic_channels_count',
                'contacts_count',
                'conversations_count',
                'problematic_channels',
                'current_month_agent_expenses',
                'current_month_meta_expenses',
                'current_month_profit',
                'current_month_paid_revenue',
                'current_month_unpaid_revenue',
                'paid_tenants_count',
                'unpaid_tenants_count',
            ]);
    }

    public function test_non_admin_cannot_access_overview()
    {
        $response = $this->actingAs($this->agentUser, 'sanctum')
            ->getJson('/api/admin/overview');

        $response->assertStatus(403);
    }
}
