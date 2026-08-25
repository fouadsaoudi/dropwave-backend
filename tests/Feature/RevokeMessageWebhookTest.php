<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Models\WabaChannel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Jobs\ProcessWebhookJob;
use App\Events\MessageDeleted;
use App\Events\ConversationUpdated;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class RevokeMessageWebhookTest extends TestCase
{
    use DatabaseTransactions;

    public function test_whatsapp_revoke_webhook_soft_deletes_message_and_broadcasts_events()
    {
        Event::fake([MessageDeleted::class, ConversationUpdated::class]);

        $tenant = Tenant::create([
            'name' => 'Revoke Test Tenant',
            'slug' => 'revoke-test-tenant',
            'email' => 'revoke@example.com',
            'password' => bcrypt('password'),
        ]);

        $role = Role::firstOrCreate(['name' => 'agent']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agent User',
            'email' => 'agent_revoke@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);

        $channel = WabaChannel::create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Revoke Channel',
            'phone_number' => '+15550783881',
            'phone_number_id' => '106540352242922',
            'waba_id' => '102290129340398',
            'access_token' => 'fake_encrypted_token',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'phone_number' => '+16505551234',
            'whatsapp_id' => '16505551234',
            'name' => 'Sheena Nelson',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'status' => 'open',
            'last_message_body' => 'Original Message to Revoke',
            'last_message_at' => now(),
            'unread_count' => 1,
        ]);

        $firstMessage = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'First older message',
            'whatsapp_msg_id' => 'wamid.FIRST_MSG_ID',
            'status' => 'delivered',
            'sent_at' => now()->subMinutes(10),
        ]);

        $targetMessage = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Original Message to Revoke',
            'whatsapp_msg_id' => 'wamid.HBgLMTQxMjU1NTA4MjkVAgASGBQzQUNCNjk5RDUwNUZGMUZEM0VBRAA=',
            'status' => 'delivered',
            'sent_at' => now()->subMinutes(2),
        ]);

        // Attach a reaction to target message
        $reaction = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'reaction',
            'reaction_emoji' => '❤️',
            'reaction_to_msg_id' => $targetMessage->id,
            'whatsapp_msg_id' => 'wamid.REACTION_MSG_ID',
            'status' => 'delivered',
            'sent_at' => now()->subMinute(),
        ]);

        // Construct Meta Revoke Webhook Payload
        $webhookPayload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '102290129340398',
                    'changes' => [
                        [
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550783881',
                                    'phone_number_id' => '106540352242922',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => [
                                            'name' => 'Sheena Nelson',
                                        ],
                                        'wa_id' => '16505551234',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '16505551234',
                                        'id' => 'wamid.HBgLMTY1MDM4Nzk0MzkVAgASGBQzQUFERjg0NDEzNDdFODU3MUMxMAA=',
                                        'timestamp' => (string)now()->timestamp,
                                        'type' => 'revoke',
                                        'revoke' => [
                                            'original_message_id' => 'wamid.HBgLMTQxMjU1NTA4MjkVAgASGBQzQUNCNjk5RDUwNUZGMUZEM0VBRAA=',
                                        ],
                                    ],
                                ],
                            ],
                            'field' => 'messages',
                        ],
                    ],
                ],
            ],
        ];

        $event = WebhookEvent::create([
            'event_type' => 'whatsapp_webhook',
            'payload' => $webhookPayload,
            'processed' => false,
        ]);

        // Process webhook job
        (new ProcessWebhookJob($event->id))->handle();

        // Assert message is soft deleted
        $this->assertNull(Message::withoutGlobalScopes()->where('id', $targetMessage->id)->whereNull('deleted_at')->first());
        $trashedMessage = Message::withoutGlobalScopes()->withTrashed()->find($targetMessage->id);
        $this->assertNotNull($trashedMessage);
        $this->assertNotNull($trashedMessage->deleted_at);

        // Assert reaction is soft-deleted
        $this->assertNull(Message::find($reaction->id));
        $trashedReaction = Message::withoutGlobalScopes()->withTrashed()->find($reaction->id);
        $this->assertNotNull($trashedReaction);
        $this->assertNotNull($trashedReaction->deleted_at);

        // Assert conversation last message is updated to the previous message
        $conversation->refresh();
        $this->assertEquals('First older message', $conversation->last_message_body);

        // Assert events were dispatched
        Event::assertDispatched(MessageDeleted::class, function ($event) use ($targetMessage, $conversation) {
            return $event->messageId === $targetMessage->id &&
                   $event->whatsappMsgId === 'wamid.HBgLMTQxMjU1NTA4MjkVAgASGBQzQUNCNjk5RDUwNUZGMUZEM0VBRAA=' &&
                   $event->conversationId === $conversation->id;
        });

        Event::assertDispatched(ConversationUpdated::class, function ($event) use ($conversation) {
            return $event->conversation->id === $conversation->id;
        });

        // Test API endpoint does not return soft deleted message
        $response = $this->actingAs($user)->getJson("/api/conversations/{$conversation->id}/messages");
        $response->assertStatus(200);
        $messages = $response->json('messages');
        $this->assertCount(1, $messages);
        $this->assertEquals($firstMessage->id, $messages[0]['id']);

        // Assert billing service still counts the conversation session even though message was revoked
        $billingService = resolve(\App\Services\TenantBillingService::class);
        $billingData = $billingService->getMonthlySnapshotSummary($tenant, now());
        $this->assertGreaterThanOrEqual(1, $billingData['conversation_sessions_count']);
    }

    public function test_whatsapp_revoke_webhook_handles_missing_target_gracefully()
    {
        Event::fake([MessageDeleted::class, ConversationUpdated::class]);

        $tenant = Tenant::create([
            'name' => 'Revoke Test Tenant 2',
            'slug' => 'revoke-test-tenant-2',
            'email' => 'revoke2@example.com',
            'password' => bcrypt('password'),
        ]);

        $channel = WabaChannel::create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Revoke Channel 2',
            'phone_number' => '+15550783882',
            'phone_number_id' => '106540352242923',
            'waba_id' => '102290129340399',
            'access_token' => 'fake_encrypted_token',
        ]);

        $webhookPayload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '102290129340399',
                    'changes' => [
                        [
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550783882',
                                    'phone_number_id' => '106540352242923',
                                ],
                                'messages' => [
                                    [
                                        'from' => '16505551234',
                                        'id' => 'wamid.REVOKE_UNKNOWN',
                                        'timestamp' => (string)now()->timestamp,
                                        'type' => 'revoke',
                                        'revoke' => [
                                            'original_message_id' => 'wamid.NON_EXISTENT_ORIGINAL',
                                        ],
                                    ],
                                ],
                            ],
                            'field' => 'messages',
                        ],
                    ],
                ],
            ],
        ];

        $event = WebhookEvent::create([
            'event_type' => 'whatsapp_webhook',
            'payload' => $webhookPayload,
            'processed' => false,
        ]);

        (new ProcessWebhookJob($event->id))->handle();

        $event->refresh();
        $this->assertTrue($event->processed);
        Event::assertNotDispatched(MessageDeleted::class);
    }
}
