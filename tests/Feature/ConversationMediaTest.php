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
use App\Jobs\ProcessWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ConversationMediaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('s3');
    }

    public function test_outbound_media_is_saved_in_conversation_folder_on_both_disks()
    {
        $this->withoutExceptionHandling();

        $msgCounter = 0;
        Http::fake([
            'https://graph.facebook.com/*' => function ($request) use (&$msgCounter) {
                $msgCounter++;
                return Http::response(['id' => 'meta_media_123', 'messages' => [['id' => 'wa_msg_unique_' . $msgCounter]]], 200);
            },
        ]);

        foreach (['public', 's3'] as $disk) {
            config(['filesystems.media_disk' => $disk]);

            $tenant = Tenant::create([
                'name' => 'Test Tenant Media ' . $disk,
                'slug' => 'test-tenant-media-' . $disk,
                'email' => 'media-' . $disk . '@example.com',
                'password' => bcrypt('password'),
            ]);

            $role = Role::firstOrCreate(['name' => 'admin']);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => 'Media User',
                'email' => 'media-user-' . $disk . '@example.com',
                'password' => bcrypt('password'),
                'role_id' => $role->id,
                'is_active' => true,
            ]);

            $channel = WabaChannel::create([
                'tenant_id' => $tenant->id,
                'display_name' => 'Waba Test Media ' . $disk,
                'phone_number' => '+15551111111',
                'phone_number_id' => '111222' . $disk,
                'waba_id' => 'waba_media_id_' . $disk,
                'access_token' => encrypt('token'),
            ]);

            $contact = Contact::create([
                'tenant_id' => $tenant->id,
                'phone_number' => '+15552222222',
                'name' => 'John Doe',
            ]);

            $conversation = Conversation::create([
                'tenant_id' => $tenant->id,
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'status' => 'open',
                'last_message_at' => now(),
                'window_expires_at' => now()->addHours(12),
            ]);

            $file = UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');

            $response = $this->actingAs($user, 'sanctum')
                ->postJson("/api/conversations/{$conversation->id}/messages", [
                    'body' => 'Test image caption',
                    'file' => $file,
                ], [
                    'X-Tenant-Id' => $tenant->id,
                ]);

            $response->assertStatus(200);

            $message = Message::where('conversation_id', $conversation->id)->first();
            $this->assertNotNull($message);

            // Verify it was stored under conversations/{conversation_id}/
            $this->assertStringStartsWith("storage/conversations/{$conversation->id}/", $message->media_url);

            $relativePath = str_replace('storage/', '', $message->media_url);
            Storage::disk($disk)->assertExists($relativePath);
        }
    }

    public function test_inbound_media_is_downloaded_and_saved_in_conversation_folder_on_both_disks()
    {
        foreach (['public', 's3'] as $disk) {
            config(['filesystems.media_disk' => $disk]);

            $tenant = Tenant::create([
                'name' => 'Test Tenant Media Inbound ' . $disk,
                'slug' => 'test-tenant-media-inbound-' . $disk,
                'email' => 'media-inbound-' . $disk . '@example.com',
                'password' => bcrypt('password'),
            ]);

            $channel = WabaChannel::create([
                'tenant_id' => $tenant->id,
                'display_name' => 'Waba Test Inbound ' . $disk,
                'phone_number' => '+15551111111',
                'phone_number_id' => '111222' . $disk,
                'waba_id' => 'waba_media_id_' . $disk,
                'access_token' => encrypt('token'),
            ]);

            // Use wildcards for graph.facebook.com endpoints to be version independent
            Http::fake([
                'https://graph.facebook.com/*/meta_inbound_media_id' => Http::response(['url' => 'https://meta.download.url/path/to/media'], 200),
                'https://meta.download.url/*' => Http::response('fake binary content of image', 200, ['Content-Type' => 'image/png']),
            ]);

            $payload = [
                'entry' => [
                    [
                        'changes' => [
                            [
                                'field' => 'messages',
                                'value' => [
                                    'messaging_product' => 'whatsapp',
                                    'metadata' => [
                                        'display_phone_number' => '15551111111',
                                        'phone_number_id' => '111222' . $disk,
                                    ],
                                    'contacts' => [
                                        [
                                            'profile' => ['name' => 'Alice'],
                                            'wa_id' => '15553333333',
                                        ]
                                    ],
                                    'messages' => [
                                        [
                                            'from' => '15553333333',
                                            'id' => 'wamid.HBgLMTU1NTMzMzMzMzM3FQIAERgSQjU1RjU4OUFBQjRGM0Q1RjZFAA==' . $disk,
                                            'timestamp' => time(),
                                            'type' => 'image',
                                            'image' => [
                                                'id' => 'meta_inbound_media_id',
                                                'mime_type' => 'image/png',
                                            ],
                                        ]
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $event = WebhookEvent::create([
                'payload' => $payload,
                'processed' => false,
            ]);

            (new ProcessWebhookJob($event->id))->handle();

            $event->refresh();
            $this->assertTrue((bool)$event->processed);

            $message = Message::withoutGlobalScopes()->where('whatsapp_msg_id', 'wamid.HBgLMTU1NTMzMzMzMzM3FQIAERgSQjU1RjU4OUFBQjRGM0Q1RjZFAA==' . $disk)->first();
            $this->assertNotNull($message);

            // Verify storage directory and database media_url update
            $this->assertNotNull($message->media_url);
            $this->assertStringStartsWith("storage/conversations/{$message->conversation_id}/", $message->media_url);

            $relativePath = str_replace('storage/', '', $message->media_url);
            Storage::disk($disk)->assertExists($relativePath);
            $this->assertEquals('fake binary content of image', Storage::disk($disk)->get($relativePath));
        }
    }

    public function test_proxy_dynamic_caching_caches_media_locally_on_both_disks()
    {
        foreach (['public', 's3'] as $disk) {
            config(['filesystems.media_disk' => $disk]);

            $tenant = Tenant::create([
                'name' => 'Test Tenant Proxy ' . $disk,
                'slug' => 'test-tenant-proxy-' . $disk,
                'email' => 'media-proxy-' . $disk . '@example.com',
                'password' => bcrypt('password'),
            ]);

            $role = Role::firstOrCreate(['name' => 'admin']);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => 'Proxy User',
                'email' => 'proxy-user-' . $disk . '@example.com',
                'password' => bcrypt('password'),
                'role_id' => $role->id,
                'is_active' => true,
            ]);

            $channel = WabaChannel::create([
                'tenant_id' => $tenant->id,
                'display_name' => 'Waba Proxy ' . $disk,
                'phone_number' => '+15551111111',
                'phone_number_id' => '111222' . $disk,
                'waba_id' => 'waba_media_id_' . $disk,
                'access_token' => encrypt('token'),
            ]);

            $contact = Contact::create([
                'tenant_id' => $tenant->id,
                'phone_number' => '+15552222222',
                'name' => 'John Doe',
            ]);

            $conversation = Conversation::create([
                'tenant_id' => $tenant->id,
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'status' => 'open',
                'last_message_at' => now(),
            ]);

            // Create message pointing to a remote URL (simulating if webhook download didn't run or failed)
            $message = Message::create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'type' => 'image',
                'media_url' => 'https://meta.download.url/path/to/media',
                'media_mime_type' => 'image/png',
                'media_filename' => 'meta_inbound_media_id',
                'whatsapp_msg_id' => 'wa_msg_123_proxy_' . $disk,
                'status' => 'delivered',
                'sent_at' => now(),
            ]);

            Http::fake([
                'https://graph.facebook.com/*/meta_inbound_media_id' => Http::response(['url' => 'https://meta.download.url/path/to/media'], 200),
                'https://meta.download.url/*' => Http::response('fake binary content of image from proxy', 200, ['Content-Type' => 'image/png']),
            ]);

            $response = $this->actingAs($user, 'sanctum')
                ->getJson("/api/media/proxy?message_id={$message->id}", [
                    'X-Tenant-Id' => $tenant->id,
                ]);

            $response->assertStatus(200);
            $response->assertHeader('Content-Type', 'image/png');
            $this->assertEquals('fake binary content of image from proxy', $response->getContent());

            // Refresh message and check if cached locally on the disk
            $message->refresh();
            $this->assertStringStartsWith("storage/conversations/{$conversation->id}/", $message->media_url);

            $relativePath = str_replace('storage/', '', $message->media_url);
            Storage::disk($disk)->assertExists($relativePath);
            $this->assertEquals('fake binary content of image from proxy', Storage::disk($disk)->get($relativePath));
        }
    }
}
