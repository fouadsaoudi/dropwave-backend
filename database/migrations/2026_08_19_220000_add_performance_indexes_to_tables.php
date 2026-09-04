<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Messages table indexes
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at'], 'idx_messages_tenant_created');
            $table->index(['tenant_id', 'direction', 'status', 'created_at'], 'idx_messages_tenant_dir_stat_created');
            $table->index(['tenant_id', 'direction', 'status', 'sent_at'], 'idx_messages_tenant_dir_stat_sent');
            $table->index(['tenant_id', 'status'], 'idx_messages_tenant_status');
            $table->index(['conversation_id', 'id'], 'idx_messages_conv_id');
            $table->index(['conversation_id', 'direction', 'created_at'], 'idx_messages_conv_dir_created');
            $table->index('created_at', 'idx_messages_created_at');
            $table->index('sent_at', 'idx_messages_sent_at');
        });

        // 2. Conversations table indexes
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['tenant_id', 'last_message_at'], 'idx_conversations_tenant_last_msg');
            $table->index(['tenant_id', 'status', 'last_message_at'], 'idx_conversations_tenant_status_last_msg');
            $table->index(['tenant_id', 'assigned_to', 'status'], 'idx_conversations_tenant_assigned_status');
            $table->index(['tenant_id', 'contact_id'], 'idx_conversations_tenant_contact');
            $table->index(['tenant_id', 'channel_id'], 'idx_conversations_tenant_channel');
            $table->index(['tenant_id', 'status', 'unread_count'], 'idx_conversations_tenant_status_unread');
        });

        // 3. Tenants table indexes
        Schema::table('tenants', function (Blueprint $table) {
            $table->index('name', 'idx_tenants_name');
            $table->index('email', 'idx_tenants_email');
            $table->index('is_active', 'idx_tenants_is_active');
            $table->index('type', 'idx_tenants_type');
            $table->index('created_at', 'idx_tenants_created_at');
        });

        // 4. Notifications table indexes
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['tenant_id', 'is_read', 'created_at'], 'idx_notifications_tenant_is_read_created');
            $table->index(['tenant_id', 'created_at'], 'idx_notifications_tenant_created');
            $table->index(['conversation_id', 'is_read'], 'idx_notifications_conv_is_read');
        });

        // 5. Message Templates table indexes
        Schema::table('message_templates', function (Blueprint $table) {
            $table->index(['tenant_id', 'status'], 'idx_msg_templates_tenant_status');
            $table->index(['channel_id', 'status'], 'idx_msg_templates_channel_status');
            $table->index(['tenant_id', 'name'], 'idx_msg_templates_tenant_name');
            $table->index('meta_template_id', 'idx_msg_templates_meta_tpl_id');
            $table->index(['tenant_id', 'category'], 'idx_msg_templates_tenant_category');
        });

        // 6. Contacts table indexes
        Schema::table('contacts', function (Blueprint $table) {
            $table->index(['tenant_id', 'name'], 'idx_contacts_tenant_name');
            $table->index('phone_number', 'idx_contacts_phone_number');
            $table->index(['tenant_id', 'opted_out'], 'idx_contacts_tenant_opted_out');
            $table->index(['tenant_id', 'created_at'], 'idx_contacts_tenant_created_at');
        });

        // 7. Calls table indexes
        Schema::table('calls', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at'], 'idx_calls_tenant_created');
            $table->index(['tenant_id', 'status'], 'idx_calls_tenant_status');
            $table->index(['tenant_id', 'direction'], 'idx_calls_tenant_dir');
        });

        // 8. Tenant Billing Snapshots table indexes
        Schema::table('tenant_billing_snapshots', function (Blueprint $table) {
            $table->index(['billing_month', 'payment_status'], 'idx_billing_snapshots_month_status');
            $table->index('billing_month', 'idx_billing_snapshots_month');
        });

        // 9. Campaign Recipients table indexes
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->index(['campaign_id', 'status'], 'idx_camp_recip_campaign_status');
        });

        // 10. Campaigns table indexes
        Schema::table('campaigns', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at'], 'idx_campaigns_tenant_created');
        });

        // 11. Orders table indexes
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['tenant_id', 'status'], 'idx_orders_tenant_status');
            $table->index(['tenant_id', 'created_at'], 'idx_orders_tenant_created');
            $table->index(['driver_id', 'status'], 'idx_orders_driver_status');
        });

        // 12. Users table indexes
        Schema::table('users', function (Blueprint $table) {
            $table->index(['tenant_id', 'is_active'], 'idx_users_tenant_is_active');
            $table->index(['tenant_id', 'role_id'], 'idx_users_tenant_role');
            $table->index('name', 'idx_users_name');
        });

        // 13. Waba Channels table indexes
        Schema::table('waba_channels', function (Blueprint $table) {
            $table->index(['tenant_id', 'is_active'], 'idx_waba_channels_tenant_active');
            $table->index(['tenant_id', 'is_primary'], 'idx_waba_channels_tenant_primary');
            $table->index('phone_number_id', 'idx_waba_channels_phone_number_id');
            $table->index('waba_id', 'idx_waba_channels_waba_id');
        });

        // 14. Drivers table indexes
        Schema::table('drivers', function (Blueprint $table) {
            $table->index(['tenant_id', 'phone_number'], 'idx_drivers_tenant_phone');
            $table->index('phone_number', 'idx_drivers_phone_number');
        });

        // 15. Opt Outs table indexes
        Schema::table('opt_outs', function (Blueprint $table) {
            $table->index(['tenant_id', 'phone_number'], 'idx_opt_outs_tenant_phone');
        });

        // 16. Webhook Events table indexes
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->index(['processed', 'created_at'], 'idx_webhook_events_processed_created');
            $table->index(['tenant_id', 'event_type'], 'idx_webhook_events_tenant_event');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropIndex('idx_webhook_events_processed_created');
            $table->dropIndex('idx_webhook_events_tenant_event');
        });

        Schema::table('opt_outs', function (Blueprint $table) {
            $table->dropIndex('idx_opt_outs_tenant_phone');
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropIndex('idx_drivers_tenant_phone');
            $table->dropIndex('idx_drivers_phone_number');
        });

        Schema::table('waba_channels', function (Blueprint $table) {
            $table->dropIndex('idx_waba_channels_tenant_active');
            $table->dropIndex('idx_waba_channels_tenant_primary');
            $table->dropIndex('idx_waba_channels_phone_number_id');
            $table->dropIndex('idx_waba_channels_waba_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_tenant_is_active');
            $table->dropIndex('idx_users_tenant_role');
            $table->dropIndex('idx_users_name');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_tenant_status');
            $table->dropIndex('idx_orders_tenant_created');
            $table->dropIndex('idx_orders_driver_status');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('idx_campaigns_tenant_created');
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropIndex('idx_camp_recip_campaign_status');
        });

        Schema::table('tenant_billing_snapshots', function (Blueprint $table) {
            $table->dropIndex('idx_billing_snapshots_month_status');
            $table->dropIndex('idx_billing_snapshots_month');
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex('idx_calls_tenant_created');
            $table->dropIndex('idx_calls_tenant_status');
            $table->dropIndex('idx_calls_tenant_dir');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('idx_contacts_tenant_name');
            $table->dropIndex('idx_contacts_phone_number');
            $table->dropIndex('idx_contacts_tenant_opted_out');
            $table->dropIndex('idx_contacts_tenant_created_at');
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropIndex('idx_msg_templates_tenant_status');
            $table->dropIndex('idx_msg_templates_channel_status');
            $table->dropIndex('idx_msg_templates_tenant_name');
            $table->dropIndex('idx_msg_templates_meta_tpl_id');
            $table->dropIndex('idx_msg_templates_tenant_category');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notifications_tenant_is_read_created');
            $table->dropIndex('idx_notifications_tenant_created');
            $table->dropIndex('idx_notifications_conv_is_read');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex('idx_tenants_name');
            $table->dropIndex('idx_tenants_email');
            $table->dropIndex('idx_tenants_is_active');
            $table->dropIndex('idx_tenants_type');
            $table->dropIndex('idx_tenants_created_at');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('idx_conversations_tenant_last_msg');
            $table->dropIndex('idx_conversations_tenant_status_last_msg');
            $table->dropIndex('idx_conversations_tenant_assigned_status');
            $table->dropIndex('idx_conversations_tenant_contact');
            $table->dropIndex('idx_conversations_tenant_channel');
            $table->dropIndex('idx_conversations_tenant_status_unread');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_tenant_created');
            $table->dropIndex('idx_messages_tenant_dir_stat_created');
            $table->dropIndex('idx_messages_tenant_dir_stat_sent');
            $table->dropIndex('idx_messages_tenant_status');
            $table->dropIndex('idx_messages_conv_id');
            $table->dropIndex('idx_messages_conv_dir_created');
            $table->dropIndex('idx_messages_created_at');
            $table->dropIndex('idx_messages_sent_at');
        });
    }
};
