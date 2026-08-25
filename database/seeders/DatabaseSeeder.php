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

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed default roles
        $adminRole = Role::create(['name' => 'admin']);
        $agentRole = Role::create(['name' => 'agent']);
        $managerRole = Role::create(['name' => 'manager']);

        // 2. Create Dropwave Admin (no tenant)
        User::create([
            'name' => 'Dropwave Admin',
            'email' => 'admin@dropwave.app',
            'password' => Hash::make('password'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        // 3. Create Click & Pick Tenant
        $tenant = Tenant::create([
            'name' => 'Click & Pick',
            'slug' => 'click-and-pick',
            'email' => 'hello@clickandpick.com',
            'phone' => '+96171417539',
            'is_active' => true,
        ]);

        // 4. Create Agent under Click & Pick
        $agent = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Click & Pick Agent',
            'email' => 'agent@clickandpick.com',
            'password' => Hash::make('password'),
            'role_id' => $agentRole->id,
            'is_active' => true,
        ]);

        // Create Manager under Click & Pick
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Click & Pick Manager',
            'email' => 'manager@clickandpick.com',
            'password' => Hash::make('password'),
            'role_id' => $managerRole->id,
            'is_active' => true,
        ]);

        // 5. Create a Mock WabaChannel for Click & Pick
        $channel = WabaChannel::create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Click & Pick Support',
            'phone_number' => '+96171417539',
            'phone_number_id' => '1183925568140001',
            'waba_id' => '1349771727341642',
            'access_token' => 'EAAdh5YqDpdgBR7uBwBDltVQGReehWtTU803CtA58xSFHHAp4zUYwzvt0GauSa2KMmg7IASa95riMEjmYpiZBhconyam5H0PNPAUhwmNP9aZBr3eZAwtYYp5RwXMqgGtKcVDZAzvNLckwNU9r17JNvJIOwJmvlGbq9MmCjEFQUK5uZCfbyZAqDtRi2WxKt0vgZDZD',
            'quality_rating' => 'GREEN',
            'is_active' => true,
            'is_primary' => true,
            'connected_at' => now(),
        ]);

        // 5. Create a Mock WabaChannel for Click & Pick
        $channel = WabaChannel::create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Test Number',
            'phone_number' => '+15556780733',
            'phone_number_id' => '1164485163424169',
            'waba_id' => '1024879630158066',
            'access_token' => 'EAAdh5YqDpdgBSWUD9LTopZBHX6CdzHbORiKMzKpAUJHzZB1ZCECcqZA3znCfivxH7LrnMEmA1gDgudRUuELZBSF3XbEqhwW1x8XBc1ZBDcTnE1OJQGfrswZB0FB15Ocaf6mNx2Xqsw7XZBZBoJhePY99dOb6t8ATQfTIYZAcHqGA1HaaLaUZBubNvFSmze9ywWB5QZDZD',
            'quality_rating' => '',
            'is_active' => true,
            'is_primary' => true,
            'connected_at' => now(),
        ]);

        // 6. Create Mock Contact
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'John Doe',
            'phone_number' => '+96176681709',
            'added_via' => 'inbound',
        ]);

        // 7. Create Mock Conversation (Active & claimed by agent)
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'status' => 'open',
            'assigned_to' => $agent->id,
            'assigned_at' => now()->subHours(2),
            'window_expires_at' => now()->addHours(22), // 24h window active
            'last_message_at' => now()->subMinutes(15),
            'last_message_body' => 'I would like to change the delivery address for order #4102',
            'unread_count' => 0,
        ]);

        // 8. Seed conversation messages
        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Hello, I want to inquire about my order.',
            'whatsapp_msg_id' => 'wamid.HBgLOTYxNzAxMjM0NTYSFQIAERgSQjE2ODU1RjlEMUI5OEY5QUQA',
            'status' => 'read',
            'sent_at' => now()->subHours(2),
            'delivered_at' => now()->subHours(2)->addSeconds(2),
            'read_at' => now()->subHours(2)->addSeconds(10),
        ]);

        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Hi John! Sure, please share your order number.',
            'whatsapp_msg_id' => 'wamid.HBgLOTYxNzAxMjM0NTYSFQIAERgSQjE2ODU1RjlEMUI5OEY5QURC',
            'status' => 'read',
            'sent_by' => $agent->id,
            'sent_at' => now()->subHour(),
            'delivered_at' => now()->subHour()->addSeconds(3),
            'read_at' => now()->subHour()->addSeconds(30),
        ]);

        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'I would like to change the delivery address for order #4102',
            'whatsapp_msg_id' => 'wamid.HBgLOTYxNzAxMjM0NTYSFQIAERgSQjE2ODU1RjlEMUI5OEY5QURD',
            'status' => 'delivered',
            'sent_at' => now()->subMinutes(15),
            'delivered_at' => now()->subMinutes(15)->addSeconds(2),
        ]);
    }
}
