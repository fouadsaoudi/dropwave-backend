<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class StressTestSeeder extends Seeder
{
    /**
     * Seed 50,000 records across tenants, users, contacts, drivers, conversations, and messages for stress testing.
     */
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        $targetCount = 50000;
        $batchSize = 1000;
        $now = Carbon::now()->toDateTimeString();
        $passwordHash = Hash::make('password');

        echo "Starting bulk stress test seeding (Target: {$targetCount} per table)..." . PHP_EOL;

        // 1. Primary Tenant (Click & Pick)
        $primaryTenantId = DB::table('tenants')->where('id', 1)->value('id');
        if (!$primaryTenantId) {
            $primaryTenantId = DB::table('tenants')->insertGetId([
                'id' => 1,
                'name' => 'Click & Pick',
                'slug' => 'click-and-pick',
                'email' => 'hello@clickandpick.com',
                'phone' => '+96171417539',
                'type' => 'delivery_coordination',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Ensure default WABA channel exists
        $channelId = DB::table('waba_channels')->where('id', 1)->value('id');
        if (!$channelId) {
            $channelId = DB::table('waba_channels')->insertGetId([
                'id' => 1,
                'tenant_id' => $primaryTenantId,
                'display_name' => 'Click & Pick Support',
                'phone_number' => '+96171417539',
                'phone_number_id' => '1183925568140001',
                'waba_id' => '1349771727341642',
                'access_token' => 'mock_token',
                'quality_rating' => 'GREEN',
                'is_active' => 1,
                'is_primary' => 1,
                'connected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 1. Seed Tenants
        $existingTenants = DB::table('tenants')->count();
        if ($existingTenants < $targetCount) {
            $needed = $targetCount - $existingTenants;
            echo "Seeding {$needed} Tenants..." . PHP_EOL;
            for ($i = 0; $i < $needed; $i += $batchSize) {
                $batch = [];
                $limit = min($batchSize, $needed - $i);
                for ($j = 0; $j < $limit; $j++) {
                    $idx = $existingTenants + $i + $j + 1;
                    $batch[] = [
                        'name' => "Tenant Company {$idx}",
                        'slug' => "tenant-company-{$idx}-" . uniqid() . '-' . mt_rand(1000, 9999),
                        'contact_name' => "Manager {$idx}",
                        'email' => "tenant_{$idx}_" . uniqid() . "@example.com",
                        'phone' => '+961' . sprintf('%08d', mt_rand(10000000, 99999999)),
                        'type' => ($idx % 2 === 0 ? 'delivery_coordination' : 'standard'),
                        'is_active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('tenants')->insert($batch);
            }
        }
        $tenantIds = DB::table('tenants')->pluck('id')->toArray();
        $tenantCount = count($tenantIds);

        // 2. Seed Users
        $existingUsers = DB::table('users')->count();
        if ($existingUsers < $targetCount) {
            $needed = $targetCount - $existingUsers;
            echo "Seeding {$needed} Users..." . PHP_EOL;
            for ($i = 0; $i < $needed; $i += $batchSize) {
                $batch = [];
                $limit = min($batchSize, $needed - $i);
                for ($j = 0; $j < $limit; $j++) {
                    $idx = $existingUsers + $i + $j + 1;
                    $tenantId = ($idx <= 25000) ? $primaryTenantId : $tenantIds[$idx % $tenantCount];
                    $batch[] = [
                        'tenant_id' => $tenantId,
                        'name' => "Agent User {$idx}",
                        'email' => "agent_{$idx}_" . uniqid() . "@example.com",
                        'password' => $passwordHash,
                        'role_id' => ($idx % 3 === 0 ? 3 : 2),
                        'is_active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('users')->insert($batch);
            }
        }
        $userIds = DB::table('users')->pluck('id')->toArray();
        $userCount = count($userIds);

        // 3. Seed Contacts
        $existingContacts = DB::table('contacts')->count();
        if ($existingContacts < $targetCount) {
            $needed = $targetCount - $existingContacts;
            echo "Seeding {$needed} Contacts..." . PHP_EOL;
            for ($i = 0; $i < $needed; $i += $batchSize) {
                $batch = [];
                $limit = min($batchSize, $needed - $i);
                for ($j = 0; $j < $limit; $j++) {
                    $idx = $existingContacts + $i + $j + 1;
                    $tenantId = ($idx <= 25000) ? $primaryTenantId : $tenantIds[$idx % $tenantCount];
                    $batch[] = [
                        'tenant_id' => $tenantId,
                        'phone_number' => '+9617' . sprintf('%07d', $idx % 10000000),
                        'name' => "Customer {$idx}",
                        'added_via' => 'inbound',
                        'opted_out' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('contacts')->insert($batch);
            }
        }
        $contactIds = DB::table('contacts')->pluck('id')->toArray();
        $contactCount = count($contactIds);

        // 4. Seed Drivers
        $existingDrivers = DB::table('drivers')->count();
        if ($existingDrivers < $targetCount) {
            $needed = $targetCount - $existingDrivers;
            echo "Seeding {$needed} Drivers..." . PHP_EOL;
            for ($i = 0; $i < $needed; $i += $batchSize) {
                $batch = [];
                $limit = min($batchSize, $needed - $i);
                for ($j = 0; $j < $limit; $j++) {
                    $idx = $existingDrivers + $i + $j + 1;
                    $tenantId = ($idx <= 25000) ? $primaryTenantId : $tenantIds[$idx % $tenantCount];
                    $batch[] = [
                        'tenant_id' => $tenantId,
                        'name' => "Driver {$idx}",
                        'phone_number' => '+9618' . sprintf('%07d', $idx % 10000000),
                        'is_active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('drivers')->insert($batch);
            }
        }

        // 5. Seed Conversations
        $existingConvos = DB::table('conversations')->count();
        if ($existingConvos < $targetCount) {
            $needed = $targetCount - $existingConvos;
            echo "Seeding {$needed} Conversations..." . PHP_EOL;
            $statuses = ['open', 'resolved', 'closed'];
            for ($i = 0; $i < $needed; $i += $batchSize) {
                $batch = [];
                $limit = min($batchSize, $needed - $i);
                for ($j = 0; $j < $limit; $j++) {
                    $idx = $existingConvos + $i + $j + 1;
                    $tenantId = ($idx <= 25000) ? $primaryTenantId : $tenantIds[$idx % $tenantCount];
                    $contactId = $contactIds[$idx % $contactCount];
                    $isUnassigned = ($idx % 3 === 0);
                    $status = $isUnassigned ? 'open' : $statuses[$idx % 3];
                    $assignedTo = $isUnassigned ? null : $userIds[$idx % $userCount];

                    $batch[] = [
                        'tenant_id' => $tenantId,
                        'contact_id' => $contactId,
                        'channel_id' => 1,
                        'status' => $status,
                        'assigned_to' => $assignedTo,
                        'assigned_at' => $assignedTo ? $now : null,
                        'last_message_at' => $now,
                        'last_message_body' => "Stress test conversation snippet #{$idx}",
                        'unread_count' => rand(0, 3),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('conversations')->insert($batch);
            }
        }
        $convoIds = DB::table('conversations')->pluck('id')->toArray();
        $convoCount = count($convoIds);

        // 6. Seed Messages
        $existingMessages = DB::table('messages')->count();
        if ($existingMessages < $targetCount) {
            $needed = $targetCount - $existingMessages;
            echo "Seeding {$needed} Messages..." . PHP_EOL;
            for ($i = 0; $i < $needed; $i += $batchSize) {
                $batch = [];
                $limit = min($batchSize, $needed - $i);
                for ($j = 0; $j < $limit; $j++) {
                    $idx = $existingMessages + $i + $j + 1;
                    $convoId = $convoIds[$idx % $convoCount];
                    $isInternal = ($idx % 5 === 0);
                    $direction = $isInternal ? 'outbound' : ($idx % 2 === 0 ? 'inbound' : 'outbound');
                    $sentBy = ($direction === 'outbound') ? $userIds[$idx % $userCount] : null;

                    $batch[] = [
                        'tenant_id' => $primaryTenantId,
                        'conversation_id' => $convoId,
                        'direction' => $direction,
                        'type' => 'text',
                        'body' => $isInternal ? "Internal Note #{$idx}: Delivery status verification." : "Stress test chat message #{$idx} text body.",
                        'whatsapp_msg_id' => $isInternal ? null : ('wamid_stress_' . $idx . '_' . uniqid()),
                        'is_internal' => $isInternal ? 1 : 0,
                        'status' => 'sent',
                        'sent_by' => $sentBy,
                        'sent_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('messages')->insert($batch);
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        echo "Stress seeding finished successfully! All tables seeded to target volume." . PHP_EOL;
    }
}
