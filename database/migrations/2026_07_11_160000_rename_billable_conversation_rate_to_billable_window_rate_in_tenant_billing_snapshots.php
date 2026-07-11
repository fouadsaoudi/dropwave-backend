<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tenant_billing_snapshots')) {
            return;
        }

        if (Schema::hasColumn('tenant_billing_snapshots', 'billable_conversation_rate') && !Schema::hasColumn('tenant_billing_snapshots', 'billable_window_rate')) {
            DB::statement(
                'ALTER TABLE tenant_billing_snapshots CHANGE billable_conversation_rate billable_window_rate DECIMAL(10,2) NOT NULL DEFAULT 0.01'
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('tenant_billing_snapshots')) {
            return;
        }

        if (Schema::hasColumn('tenant_billing_snapshots', 'billable_window_rate') && !Schema::hasColumn('tenant_billing_snapshots', 'billable_conversation_rate')) {
            DB::statement(
                'ALTER TABLE tenant_billing_snapshots CHANGE billable_window_rate billable_conversation_rate DECIMAL(10,2) NOT NULL DEFAULT 0.01'
            );
        }
    }
};
