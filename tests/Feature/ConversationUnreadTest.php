<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Models\Conversation;
use App\Models\Contact;
use App\Models\WabaChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ConversationUnreadTest extends TestCase
{
    use RefreshDatabase;

    private User $agentUser;
    private Role $agentRole;
    private Tenant $tenant;
    private WabaChannel $channel;
    private Contact $contact;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agentRole = Role::firstOrCreate(['name' => 'agent']);

        $this->tenant = Tenant::create([
            'name' => 'Unread Test Tenant',
            'slug' => 'unread-test-tenant',
            'email' => 'unread-test-tenant@example.com',
        ]);

        $this->channel = WabaChannel::create([
            'tenant_id' => $this->tenant->id,
            'display_name' => 'Unread Test Channel',
            'phone_number' => '+15551234567',
            'phone_number_id' => '123456',
            'waba_id' => 'waba_123',
            'access_token' => encrypt('token'),
            'is_active' => true,
        ]);

        $this->agentUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Agent Unread Tester',
            'email' => 'agent-unread@example.com',
            'password' => bcrypt('password'),
            'role_id' => $this->agentRole->id,
            'is_active' => true,
        ]);

        $this->contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Customer Unread',
            'phone_number' => '+15559998888',
        ]);

        $this->conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'channel_id' => $this->channel->id,
            'status' => 'open',
            'assigned_to' => $this->agentUser->id,
            'unread_count' => 0,
        ]);
    }

    public function test_can_mark_conversation_as_unread()
    {
        $this->assertEquals(0, $this->conversation->unread_count);

        $response = $this->actingAs($this->agentUser, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/unread");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Conversation marked as unread.',
            ]);

        $this->conversation->refresh();
        $this->assertEquals(1, $this->conversation->unread_count);
    }

    public function test_can_mark_conversation_as_read()
    {
        $this->conversation->update(['unread_count' => 3]);

        $response = $this->actingAs($this->agentUser, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/read");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Conversation marked as read.',
            ]);

        $this->conversation->refresh();
        $this->assertEquals(0, $this->conversation->unread_count);
    }
}
