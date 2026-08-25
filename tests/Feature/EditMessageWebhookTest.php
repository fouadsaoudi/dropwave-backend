<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WabaChannel;
use App\Models\WebhookEvent;
use App\Jobs\ProcessWebhookJob;
use App\Events\MessageBroadcasted;
use App\Events\ConversationUpdated;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;

class EditMessageWebhookTest extends TestCase
{
    use DatabaseTransactions;

    public function test_whatsapp_edit_webhook_updates_message_body_and_broadcasts_events()
    {
        Event::fake([
            MessageBroadcasted::class,
            ConversationUpdated::class,
        ]);

        $role = Role::firstOrCreate(['name' => 'agent']);

        $tenant = Tenant::create([
            'name' => 'Edit Test Tenant',
            'slug' => 'edit-test-tenant-' . uniqid(),
            'email' => 'edit-' . uniqid() . '@example.com',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Agent User',
            'email' => 'agent-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
        ]);

        $channel = WabaChannel::create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Test Channel',
            'phone_number' => '+15550783881',
            'phone_number_id' => '106540352242922',
            'waba_id' => '102290129340398',
            'access_token' => 'mock_token',
            'is_active' => true,
            'is_primary' => true,
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sheena Nelson',
            'phone_number' => '+16505551234',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'status' => 'open',
            'assigned_to' => $user->id,
            'last_message_at' => now()->subMinutes(5),
            'last_message_body' => 'Original text message before edit',
        ]);

        $targetMessage = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Original text message before edit',
            'whatsapp_msg_id' => 'wamid.HBgLMTQxMjU1NTA4MjkVAgASGBQzQUNCNjk5RDUwNUZGMUZEM0VBRAA=',
            'status' => 'delivered',
            'sent_at' => now()->subMinutes(5),
        ]);

        $webhookPayload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '102290129340398',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550783881',
                                    'phone_number_id' => '106540352242922',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => ['name' => 'Sheena Nelson'],
                                        'wa_id' => '16505551234',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '16505551234',
                                        'id' => 'wamid.HBgLMTY1MDM4Nzk0MzkVAgASGBQzQUFERjg0NDEzNDdFODU3MUMxMAA=',
                                        'timestamp' => (string) now()->timestamp,
                                        'type' => 'edit',
                                        'edit' => [
                                            'original_message_id' => 'wamid.HBgLMTQxMjU1NTA4MjkVAgASGBQzQUNCNjk5RDUwNUZGMUZEM0VBRAA=',
                                            'message' => [
                                                'type' => 'text',
                                                'text' => [
                                                    'body' => 'This is the edited message content!',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
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

        $targetMessage->refresh();
        $this->assertEquals('This is the edited message content!', $targetMessage->body);
        $this->assertTrue($targetMessage->is_edited);

        $conversation->refresh();
        $this->assertEquals('This is the edited message content!', $conversation->last_message_body);

        Event::assertDispatched(MessageBroadcasted::class, function ($event) use ($targetMessage) {
            return $event->message->id === $targetMessage->id &&
                   $event->message->body === 'This is the edited message content!' &&
                   $event->message->is_edited === true;
        });

        Event::assertDispatched(ConversationUpdated::class, function ($event) use ($conversation) {
            return $event->conversation->id === $conversation->id;
        });
    }

    public function test_whatsapp_edit_webhook_handles_image_caption_edit()
    {
        Event::fake([
            MessageBroadcasted::class,
            ConversationUpdated::class,
        ]);

        $role = Role::firstOrCreate(['name' => 'agent']);

        $tenant = Tenant::create([
            'name' => 'Edit Media Test Tenant',
            'slug' => 'edit-media-tenant-' . uniqid(),
            'email' => 'edit-media-' . uniqid() . '@example.com',
        ]);

        $channel = WabaChannel::create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Test Channel',
            'phone_number' => '+15550783881',
            'phone_number_id' => '106540352242922',
            'waba_id' => '102290129340398',
            'access_token' => 'mock_token',
            'is_active' => true,
            'is_primary' => true,
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sheena Nelson',
            'phone_number' => '+16505551234',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'status' => 'open',
            'last_message_body' => 'Old image caption',
        ]);

        $targetMessage = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'image',
            'body' => 'Old image caption',
            'media_url' => 'https://example.com/image.jpg',
            'whatsapp_msg_id' => 'wamid.HBgLMTQxMjU1NTA4MjkVAgASGBQzQUNCNjk5RDUwNUZGMUZEM0VCRAA=',
            'status' => 'delivered',
            'sent_at' => now()->subMinutes(2),
        ]);

        $webhookPayload = [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => '102290129340398',
                    'changes' => [
                        [
                            'field' => 'messages',
                            'value' => [
                                'messaging_product' => 'whatsapp',
                                'metadata' => [
                                    'display_phone_number' => '15550783881',
                                    'phone_number_id' => '106540352242922',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => ['name' => 'Sheena Nelson'],
                                        'wa_id' => '16505551234',
                                    ],
                                ],
                                'messages' => [
                                    [
                                        'from' => '16505551234',
                                        'id' => 'wamid.HBgLMTY1MDM4Nzk0MzkVAgASGBQzQUFERjg0NDEzNDdFODU3MUMxMAA=',
                                        'timestamp' => (string) now()->timestamp,
                                        'type' => 'edit',
                                        'edit' => [
                                            'original_message_id' => 'wamid.HBgLMTQxMjU1NTA4MjkVAgASGBQzQUNCNjk5RDUwNUZGMUZEM0VCRAA=',
                                            'message' => [
                                                'type' => 'image',
                                                'image' => [
                                                    'caption' => 'Updated image caption!',
                                                    'mime_type' => 'image/jpeg',
                                                    'id' => '1234567890',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
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

        $targetMessage->refresh();
        $this->assertEquals('Updated image caption!', $targetMessage->body);
        $this->assertTrue($targetMessage->is_edited);

        $conversation->refresh();
        $this->assertEquals('Updated image caption!', $conversation->last_message_body);
    }
}
