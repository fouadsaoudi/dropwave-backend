<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\WabaChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class WabaChannelToggleCallingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_can_toggle_calling_enabled_status()
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
        ]);

        $tenant = Tenant::create([
            'name' => 'Test Tenant Toggle Unique',
            'slug' => 'test-tenant-toggle-unique',
            'email' => 'toggle-unique@example.com',
            'password' => bcrypt('password'),
        ]);

        $role = Role::firstOrCreate(['name' => 'admin']);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user-unique@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $channel = WabaChannel::create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Waba Test Calling',
            'phone_number' => '+15550000000',
            'phone_number_id' => '987654',
            'waba_id' => 'waba_test_987',
            'access_token' => encrypt('token'),
            'calling_enabled' => true,
        ]);

        // Send request to toggle calling setting
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/channels/{$channel->id}/toggle-calling", [], [
                'X-Tenant-Id' => $tenant->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'calling_enabled' => false,
            ]);

        // Verify local state in DB
        $this->assertDatabaseHas('waba_channels', [
            'id' => $channel->id,
            'calling_enabled' => false,
        ]);

        // Verify Meta API call was made with DISABLED
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/987654/settings') &&
                $request['calling']['status'] === 'DISABLED';
        });
    }
}
