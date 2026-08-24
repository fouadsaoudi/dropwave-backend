<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ContactSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'agent']);

        $this->tenant = Tenant::create([
            'name' => 'Sync Test Tenant',
            'slug' => 'sync-test-tenant-' . uniqid(),
            'email' => 'sync-test-' . uniqid() . '@example.com',
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sync Agent',
            'email' => 'sync-agent-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    public function test_can_batch_sync_device_contacts()
    {
        $payload = [
            'contacts' => [
                [
                    'name' => 'Alice Smith',
                    'phone_number' => '+15551234567',
                ],
                [
                    'name' => 'Bob Jones',
                    'phone_number' => '15559876543',
                ],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/contacts/sync', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'synced_count' => 2,
                'new_count' => 2,
            ]);

        $this->assertDatabaseHas('contacts', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Alice Smith',
            'phone_number' => '+15551234567',
        ]);

        $this->assertDatabaseHas('contacts', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Bob Jones',
            'phone_number' => '+15559876543',
        ]);
    }

    public function test_batch_sync_updates_existing_unnamed_contacts()
    {
        // Pre-create unnamed contact
        Contact::create([
            'tenant_id' => $this->tenant->id,
            'phone_number' => '+15550001111',
            'name' => '+15550001111',
            'added_via' => 'manual',
        ]);

        $payload = [
            'contacts' => [
                [
                    'name' => 'Named Person',
                    'phone_number' => '+15550001111',
                ],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/contacts/sync', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'synced_count' => 1,
                'updated_count' => 1,
            ]);

        $this->assertDatabaseHas('contacts', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Named Person',
            'phone_number' => '+15550001111',
        ]);
    }
}
