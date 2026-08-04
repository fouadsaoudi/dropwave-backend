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
    }

    public function test_outbound_media_is_saved_in_conversation_folder()
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant Media',
            'slug' => 'test-tenant-media',
            'email' => 'media@example.com',
            'password' => bcrypt('password'),
        ]);

        $role = Role::firstOrCreate(['name' => 'admin']);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Media User',
            'email' => 'media-user@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $channel = WabaChannel::create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Waba Test Media',
            'phone_number' => '+15551111111',
            'phone_number_id' => '111222',
            'waba_id' => 'waba_media_id',
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

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => 'meta_media_123', 'messages' => [['id' => 'wa_msg_123']]], 200),
        ]);

        // Use create() instead of image() to avoid GD extension dependency
        $file = UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'body' => 'Test image caption',
                'file' => $file,
            ], [
                'X-Tenant-Id' => $tenant->id,
            ]);

        if ($response->status() !== 200) {
            dd($response->status(), $response->content());
        }

        $response->assertStatus(200);

        $message = Message::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($message);

        // Verify it was stored in conversations/{conversation_id}/
        $this->assertStringStartsWith("storage/conversations/{$conversation->id}/", $message->media_url);

        $relativePath = str_replace('storage/', '', $message->media_url);
        Storage::disk('public')->assertExists($relativePath);
    }

    public function test_inbound_media_is_downloaded_and_saved_in_conversation_folder()
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant Media Inbound',
            'slug' => 'test-tenant-media-inbound',
            'email' => 'media-inbound@example.com',
            'password' => bcrypt('password'),
        ]);

        $channel = WabaChannel::create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Waba Test Inbound',
            'phone_number' => '+15551111111',
            'phone_number_id' => '111222',
            'waba_id' => 'waba_media_id',
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
                                    'phone_number_id' => '111222',
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
                                        'id' => 'wamid.HBgLMTU1NTMzMzMzMzM3FQIAERgSQjU1RjU4OUFBQjRGM0Q1RjZFAA==',
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

        $message = Message::withoutGlobalScopes()->where('whatsapp_msg_id', 'wamid.HBgLMTU1NTMzMzMzMzM3FQIAERgSQjU1RjU4OUFBQjRGM0Q1RjZFAA==')->first();
        $this->assertNotNull($message);

        // Verify storage directory and database media_url update
        $this->assertNotNull($message->media_url);
        $this->assertStringStartsWith("storage/conversations/{$message->conversation_id}/", $message->media_url);

        $relativePath = str_replace('storage/', '', $message->media_url);
        Storage::disk('public')->assertExists($relativePath);
        $this->assertEquals('fake binary content of image', Storage::disk('public')->get($relativePath));
    }

    public function test_proxy_dynamic_caching_caches_media_locally()
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant Proxy',
            'slug' => 'test-tenant-proxy',
            'email' => 'media-proxy@example.com',
            'password' => bcrypt('password'),
        ]);

        $role = Role::firstOrCreate(['name' => 'admin']);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Proxy User',
            'email' => 'proxy-user@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $channel = WabaChannel::create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Waba Proxy',
            'phone_number' => '+15551111111',
            'phone_number_id' => '111222',
            'waba_id' => 'waba_media_id',
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
            'whatsapp_msg_id' => 'wa_msg_123_proxy',
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

        // Refresh message and check if cached locally
        $message->refresh();
        $this->assertStringStartsWith("storage/conversations/{$conversation->id}/", $message->media_url);

        $relativePath = str_replace('storage/', '', $message->media_url);
        Storage::disk('public')->assertExists($relativePath);
        $this->assertEquals('fake binary content of image from proxy', Storage::disk('public')->get($relativePath));
    }
}
