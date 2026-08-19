<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Models\Conversation;
use App\Models\Contact;
use App\Models\WabaChannel;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class AgentConversationRestrictionTest extends TestCase
{
    use DatabaseTransactions;

    private User $agentUser;
    private User $otherAgent;
    private Role $agentRole;
    private Tenant $tenant;
    private WabaChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agentRole = Role::firstOrCreate(['name' => 'agent']);
        
        $this->tenant = Tenant::create([
            'name' => 'Agent Restriction Test Tenant',
            'slug' => 'agent-rest-tenant',
            'email' => 'agent-rest-tenant@example.com',
        ]);

        $this->channel = WabaChannel::create([
            'tenant_id' => $this->tenant->id,
            'display_name' => 'Agent Test Channel',
            'phone_number' => '+15559876543',
            'phone_number_id' => '987654',
            'waba_id' => 'waba_rest_123',
            'access_token' => encrypt('token'),
            'is_active' => true,
        ]);

        $this->agentUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Agent One',
            'email' => 'agent-one@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
            'is_active' => true,
        ]);

        $this->otherAgent = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Agent Two',
            'email' => 'agent-two@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
            'is_active' => true,
        ]);
    }

    public function test_agent_can_only_list_assigned_conversations()
    {
        $contact1 = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer A',
            'phone_number' => '+15551000001',
        ]);

        $contact2 = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer B',
            'phone_number' => '+15551000002',
        ]);

        // Conversation 1 assigned to Agent One
        $conv1 = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel->id,
            'contact_id' => $contact1->id,
            'status' => 'open',
            'assigned_to' => $this->agentUser->id,
        ]);

        // Conversation 2 assigned to Agent Two
        $conv2 = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel->id,
            'contact_id' => $contact2->id,
            'status' => 'open',
            'assigned_to' => $this->otherAgent->id,
        ]);

        $response = $this->actingAs($this->agentUser, 'sanctum')
            ->getJson('/api/conversations', [
                'X-Tenant-Id' => $this->tenant->id,
            ]);

        $response->assertStatus(200);
        $data = $response->json('data') ?? $response->json();
        $ids = collect($data)->pluck('id')->toArray();
        $this->assertContains($conv1->id, $ids);
        $this->assertNotContains($conv2->id, $ids);
    }

    public function test_agent_counts_reflects_only_their_chats()
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer C',
            'phone_number' => '+15551000003',
        ]);

        // Conversation assigned to Agent Two
        Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_to' => $this->otherAgent->id,
        ]);

        $response = $this->actingAs($this->agentUser, 'sanctum')
            ->getJson('/api/conversations/counts', [
                'X-Tenant-Id' => $this->tenant->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'active' => 0,
                'unassigned' => 0,
                'resolved' => 0,
            ]);
    }

    public function test_agent_blocked_from_fetching_messages_of_unassigned_or_other_agent_chats()
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer D',
            'phone_number' => '+15551000004',
        ]);

        $conv = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_to' => $this->otherAgent->id,
        ]);

        $response = $this->actingAs($this->agentUser, 'sanctum')
            ->getJson("/api/conversations/{$conv->id}/messages", [
                'X-Tenant-Id' => $this->tenant->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_agent_blocked_from_sending_messages_to_other_agent_chats()
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer E',
            'phone_number' => '+15551000005',
        ]);

        $conv = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_to' => $this->otherAgent->id,
        ]);

        $response = $this->actingAs($this->agentUser, 'sanctum')
            ->postJson("/api/conversations/{$conv->id}/messages", [
                'body' => 'Hacked text',
            ], [
                'X-Tenant-Id' => $this->tenant->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_agent_cannot_claim_other_agent_chats()
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer F',
            'phone_number' => '+15551000006',
        ]);

        $conv = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_to' => $this->otherAgent->id,
        ]);

        $response = $this->actingAs($this->agentUser, 'sanctum')
            ->postJson("/api/conversations/{$conv->id}/claim", [], [
                'X-Tenant-Id' => $this->tenant->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_agent_cannot_resolve_other_agent_chats()
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer G',
            'phone_number' => '+15551000007',
        ]);

        $conv = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_to' => $this->otherAgent->id,
        ]);

        $response = $this->actingAs($this->agentUser, 'sanctum')
            ->postJson("/api/conversations/{$conv->id}/resolve", [], [
                'X-Tenant-Id' => $this->tenant->id,
            ]);

        $response->assertStatus(403);
    }
}
