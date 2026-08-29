<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Models\WabaChannel;
use App\Models\MessageTemplate;
use App\Services\MetaApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class TenantMultiChannelTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private Tenant $tenant;
    private WabaChannel $channel1;
    private WabaChannel $channel2;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        $this->tenant = Tenant::create([
            'name' => 'Click & Pick Test',
            'slug' => 'click-and-pick-' . uniqid(),
            'email' => 'clickpick-' . uniqid() . '@example.com',
        ]);

        $this->adminUser = User::create([
            'tenant_id' => null,
            'name' => 'Admin Test',
            'email' => 'admin-tmpl-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $this->channel1 = WabaChannel::create([
            'tenant_id' => $this->tenant->id,
            'display_name' => 'Click & Pick Channel 1',
            'phone_number' => '+15551234567',
            'phone_number_id' => 'PN_ID_1_' . uniqid(),
            'waba_id' => 'WABA_1_' . uniqid(),
            'access_token' => 'dummy_token_1',
            'is_active' => true,
            'is_primary' => true,
        ]);

        $this->channel2 = WabaChannel::create([
            'tenant_id' => $this->tenant->id,
            'display_name' => 'Click & Pick Channel 2',
            'phone_number' => '+15559876543',
            'phone_number_id' => 'PN_ID_2_' . uniqid(),
            'waba_id' => 'WABA_2_' . uniqid(),
            'access_token' => 'dummy_token_2',
            'is_active' => true,
            'is_primary' => false,
        ]);
    }

    public function test_admin_can_list_templates_and_filter_by_channel_id()
    {
        $tmpl1 = MessageTemplate::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel1->id,
            'name' => 'channel_1_welcome',
            'category' => 'UTILITY',
            'language' => 'en_US',
            'status' => 'APPROVED',
            'body' => 'Welcome from channel 1',
        ]);

        $tmpl2 = MessageTemplate::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel2->id,
            'name' => 'channel_2_support',
            'category' => 'MARKETING',
            'language' => 'ar',
            'status' => 'APPROVED',
            'body' => 'Support promo from channel 2',
        ]);

        // 1. List all templates for tenant
        $responseAll = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/admin/tenants/{$this->tenant->id}/templates");

        $responseAll->assertStatus(200);
        $this->assertCount(2, $responseAll->json());

        // 2. Filter by Channel 1
        $responseCh1 = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/admin/tenants/{$this->tenant->id}/templates?channel_id={$this->channel1->id}");

        $responseCh1->assertStatus(200);
        $dataCh1 = $responseCh1->json();
        $this->assertCount(1, $dataCh1);
        $this->assertEquals($tmpl1->id, $dataCh1[0]['id']);
        $this->assertEquals($this->channel1->id, $dataCh1[0]['channel_id']);
        $this->assertEquals('Click & Pick Channel 1', $dataCh1[0]['channel']['display_name']);

        // 3. Filter by Channel 2
        $responseCh2 = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/admin/tenants/{$this->tenant->id}/templates?channel_id={$this->channel2->id}");

        $responseCh2->assertStatus(200);
        $dataCh2 = $responseCh2->json();
        $this->assertCount(1, $dataCh2);
        $this->assertEquals($tmpl2->id, $dataCh2[0]['id']);
        $this->assertEquals($this->channel2->id, $dataCh2[0]['channel_id']);
        $this->assertEquals('Click & Pick Channel 2', $dataCh2[0]['channel']['display_name']);
    }

    public function test_admin_syncs_all_active_channels_when_no_channel_id_provided()
    {
        $metaMock = Mockery::mock(MetaApiService::class);

        // Expect fetchMessageTemplates for channel 1
        $metaMock->shouldReceive('fetchMessageTemplates')
            ->with($this->channel1->decrypted_token, $this->channel1->waba_id)
            ->once()
            ->andReturn([
                'data' => [
                    [
                        'id' => 'meta_tpl_c1_123',
                        'name' => 'c1_order_update',
                        'category' => 'UTILITY',
                        'language' => 'en_US',
                        'status' => 'APPROVED',
                        'components' => [
                            ['type' => 'BODY', 'text' => 'Order {{1}} is on the way!'],
                        ],
                    ]
                ]
            ]);

        // Expect fetchMessageTemplates for channel 2
        $metaMock->shouldReceive('fetchMessageTemplates')
            ->with($this->channel2->decrypted_token, $this->channel2->waba_id)
            ->once()
            ->andReturn([
                'data' => [
                    [
                        'id' => 'meta_tpl_c2_456',
                        'name' => 'c2_flash_deal',
                        'category' => 'MARKETING',
                        'language' => 'en_US',
                        'status' => 'APPROVED',
                        'components' => [
                            ['type' => 'BODY', 'text' => 'Get 20% off today!'],
                        ],
                    ]
                ]
            ]);

        $this->app->instance(MetaApiService::class, $metaMock);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/admin/tenants/{$this->tenant->id}/templates/sync");

        $response->assertStatus(200)
            ->assertJson([
                'synced_count' => 2,
            ]);

        $tpl1 = MessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'c1_order_update')
            ->first();

        $this->assertNotNull($tpl1);
        $this->assertEquals($this->channel1->id, $tpl1->channel_id);
        $this->assertEquals('meta_tpl_c1_123', $tpl1->meta_template_id);

        $tpl2 = MessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'c2_flash_deal')
            ->first();

        $this->assertNotNull($tpl2);
        $this->assertEquals($this->channel2->id, $tpl2->channel_id);
        $this->assertEquals('meta_tpl_c2_456', $tpl2->meta_template_id);
    }

    public function test_admin_can_sync_single_target_channel()
    {
        $metaMock = Mockery::mock(MetaApiService::class);

        // Only Channel 2 should be queried
        $metaMock->shouldReceive('fetchMessageTemplates')
            ->with($this->channel2->decrypted_token, $this->channel2->waba_id)
            ->once()
            ->andReturn([
                'data' => [
                    [
                        'id' => 'meta_tpl_c2_999',
                        'name' => 'c2_exclusive_deal',
                        'category' => 'MARKETING',
                        'language' => 'en_US',
                        'status' => 'APPROVED',
                        'components' => [
                            ['type' => 'BODY', 'text' => 'Channel 2 deal!'],
                        ],
                    ]
                ]
            ]);

        $this->app->instance(MetaApiService::class, $metaMock);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson("/api/admin/tenants/{$this->tenant->id}/templates/sync", [
                'channel_id' => $this->channel2->id
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'synced_count' => 1,
            ]);

        $tpl = MessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('name', 'c2_exclusive_deal')
            ->first();

        $this->assertNotNull($tpl);
        $this->assertEquals($this->channel2->id, $tpl->channel_id);
    }

    public function test_tenant_user_can_filter_templates_by_channel_and_conversation()
    {
        $tenantUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Agent User',
            'email' => 'agent-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role_id' => Role::firstOrCreate(['name' => 'agent'])->id,
            'is_active' => true,
        ]);

        $tmpl1 = MessageTemplate::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel1->id,
            'name' => 'channel_1_welcome',
            'category' => 'UTILITY',
            'language' => 'en_US',
            'status' => 'APPROVED',
            'body' => 'Welcome from channel 1',
        ]);

        $tmpl2 = MessageTemplate::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel2->id,
            'name' => 'channel_2_support',
            'category' => 'MARKETING',
            'language' => 'ar',
            'status' => 'APPROVED',
            'body' => 'Support promo from channel 2',
        ]);

        $contact = \App\Models\Contact::create([
            'tenant_id' => $this->tenant->id,
            'phone_number' => '+15550001111',
            'name' => 'Test Customer',
        ]);

        $conversationOnCh2 = \App\Models\Conversation::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'channel_id' => $this->channel2->id,
            'status' => 'open',
            'unread_count' => 0,
        ]);

        // 1. Filter by Channel 1
        $resCh1 = $this->actingAs($tenantUser, 'sanctum')
            ->getJson("/api/templates?channel_id={$this->channel1->id}");

        $resCh1->assertStatus(200);
        $dataCh1 = $resCh1->json();
        $this->assertCount(1, $dataCh1);
        $this->assertEquals($tmpl1->id, $dataCh1[0]['id']);

        // 2. Filter by Conversation (which is on Channel 2)
        $resConv = $this->actingAs($tenantUser, 'sanctum')
            ->getJson("/api/templates?conversation_id={$conversationOnCh2->id}");

        $resConv->assertStatus(200);
        $dataConv = $resConv->json();
        $this->assertCount(1, $dataConv);
        $this->assertEquals($tmpl2->id, $dataConv[0]['id']);
        $this->assertEquals($this->channel2->id, $dataConv[0]['channel_id']);
    }
}
