<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Models\Conversation;
use App\Models\Contact;
use App\Models\Driver;
use App\Models\WabaChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class DriverConversationNameFallbackTest extends TestCase
{
    use DatabaseTransactions;

    private Role $agentRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agentRole = Role::firstOrCreate(['name' => 'agent']);
    }

    public function test_driver_name_fallback_for_delivery_coordination_tenant()
    {
        // 1. Create a delivery_coordination tenant
        $deliveryTenant = Tenant::create([
            'name' => 'Delivery Coordination Tenant',
            'slug' => 'delivery-coord',
            'type' => 'delivery_coordination',
            'email' => 'delivery@example.com',
        ]);

        $channel = WabaChannel::create([
            'tenant_id' => $deliveryTenant->id,
            'display_name' => 'Delivery Support Channel',
            'phone_number' => '+96171000000',
            'phone_number_id' => '1000000',
            'waba_id' => 'waba_del_123',
            'access_token' => encrypt('token'),
            'is_active' => true,
        ]);

        $agent = User::create([
            'tenant_id' => $deliveryTenant->id,
            'name' => 'Delivery Agent',
            'email' => 'delagent@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
            'is_active' => true,
        ]);

        // 2. Create a Driver
        $driver = Driver::create([
            'tenant_id' => $deliveryTenant->id,
            'name' => 'Driver John Doe',
            'phone_number' => '+96170123456',
            'is_active' => true,
        ]);

        // 3. Create a Contact with matching phone number and name = null
        $contact = Contact::create([
            'tenant_id' => $deliveryTenant->id,
            'name' => null,
            'phone_number' => '+96170123456',
        ]);

        // 4. Create a Conversation
        $conversation = Conversation::create([
            'tenant_id' => $deliveryTenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_to' => $agent->id,
        ]);

        // 5. Make request and assert contact name fallback works
        $response = $this->actingAs($agent, 'sanctum')
            ->getJson('/api/conversations?status=open&assigned=true&drivers=true');

        $response->assertStatus(200);
        $data = $response->json();
        
        $this->assertNotEmpty($data);
        $this->assertEquals('Driver John Doe', $data[0]['contact']['name']);
    }

    public function test_no_driver_name_fallback_for_standard_tenant()
    {
        // 1. Create a standard/other type tenant
        $standardTenant = Tenant::create([
            'name' => 'Standard Support Tenant',
            'slug' => 'standard-support',
            'type' => 'support',
            'email' => 'support@example.com',
        ]);

        $channel = WabaChannel::create([
            'tenant_id' => $standardTenant->id,
            'display_name' => 'Standard Support Channel',
            'phone_number' => '+96172000000',
            'phone_number_id' => '2000000',
            'waba_id' => 'waba_std_123',
            'access_token' => encrypt('token'),
            'is_active' => true,
        ]);

        $agent = User::create([
            'tenant_id' => $standardTenant->id,
            'name' => 'Support Agent',
            'email' => 'stdagent@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
            'is_active' => true,
        ]);

        // 2. Create a Driver (even if drivers exist in system, fallback shouldn't run for standard tenant conversations)
        $driver = Driver::create([
            'tenant_id' => $standardTenant->id,
            'name' => 'Driver John Doe',
            'phone_number' => '+96170123456',
            'is_active' => true,
        ]);

        // 3. Create a Contact with matching phone number and name = null
        $contact = Contact::create([
            'tenant_id' => $standardTenant->id,
            'name' => null,
            'phone_number' => '+96170123456',
        ]);

        // 4. Create a Conversation
        $conversation = Conversation::create([
            'tenant_id' => $standardTenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_to' => $agent->id,
        ]);

        // 5. Make request and assert contact name does NOT fall back
        $response = $this->actingAs($agent, 'sanctum')
            ->getJson('/api/conversations?status=open&assigned=true');

        $response->assertStatus(200);
        $data = $response->json();
        
        $this->assertNotEmpty($data);
        $this->assertNull($data[0]['contact']['name']);
    }
}
