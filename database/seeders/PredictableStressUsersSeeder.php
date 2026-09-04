<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Role;
use App\Models\WabaChannel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Hash;

class PredictableStressUsersSeeder extends Seeder
{
    /**
     * Seeds predictable multi-tenant users and conversations for k6 automated load testing.
     * 10 Tenants x 10 Agents = 100 concurrent test accounts.
     */
    public function run(): void
    {
        $agentRole = Role::firstOrCreate(['name' => 'agent']);
        $password = Hash::make('password');

        $tenantCount = 10;
        $usersPerTenant = 10;

        echo "Seeding {$tenantCount} Tenants with {$usersPerTenant} Agents each for Load Testing...\n";

        for ($t = 1; $t <= $tenantCount; $t++) {
            $tenant = Tenant::firstOrCreate(
                ['slug' => "stress-tenant-{$t}"],
                [
                    'name' => "Stress Tenant {$t}",
                    'email' => "tenant{$t}@stress.test",
                    'phone' => "+9617000000{$t}",
                    'type' => 'delivery_coordination',
                    'is_active' => true,
                ]
            );

            // Channel for tenant
            $channel = WabaChannel::firstOrCreate(
                ['tenant_id' => $tenant->id, 'phone_number_id' => "stress_phone_id_{$tenant->id}"],
                [
                    'display_name' => "Channel {$t}",
                    'phone_number' => "+9617000000{$t}",
                    'waba_id' => "stress_waba_{$tenant->id}",
                    'access_token' => 'stress_token',
                    'quality_rating' => 'GREEN',
                    'is_active' => true,
                    'is_primary' => true,
                    'connected_at' => now(),
                ]
            );

            for ($u = 1; $u <= $usersPerTenant; $u++) {
                $email = "stress_t{$t}_u{$u}@stress.test";

                $user = User::updateOrCreate(
                    ['email' => $email],
                    [
                        'tenant_id' => $tenant->id,
                        'name' => "Agent T{$t} U{$u}",
                        'password' => $password,
                        'role_id' => $agentRole->id,
                        'is_active' => true,
                    ]
                );

                // Create a contact for this user's active conversation
                $contact = Contact::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'phone_number' => "+9617100{$t}" . sprintf('%03d', $u),
                    ],
                    [
                        'name' => "Test Customer T{$t} U{$u}",
                        'added_via' => 'inbound',
                    ]
                );

                // Create an open active conversation
                $conversation = Conversation::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'contact_id' => $contact->id,
                    ],
                    [
                        'channel_id' => $channel->id,
                        'status' => 'open',
                        'assigned_to' => $user->id,
                        'assigned_at' => now(),
                        'window_expires_at' => now()->addHours(24),
                        'last_message_at' => now(),
                        'last_message_body' => "Ready for stress testing",
                        'unread_count' => 0,
                    ]
                );

                // Seed initial message if empty
                if ($conversation->messages()->count() === 0) {
                    Message::create([
                        'tenant_id' => $tenant->id,
                        'conversation_id' => $conversation->id,
                        'direction' => 'inbound',
                        'type' => 'text',
                        'body' => 'Hello, this is an automated load testing thread.',
                        'status' => 'read',
                        'sent_at' => now(),
                    ]);
                }
            }
        }

        echo "✅ Finished seeding 100 test agents across 10 tenants (passwords: 'password').\n";
    }
}
