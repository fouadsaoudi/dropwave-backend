<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            if (Schema::hasColumn('message_templates', 'billing_cost')) {
                DB::statement('ALTER TABLE message_templates MODIFY billing_cost DECIMAL(10,4) NULL');
            }

            if (Schema::hasColumn('tenant_billing_snapshots', 'billable_window_rate')) {
                DB::statement('ALTER TABLE tenant_billing_snapshots MODIFY billable_window_rate DECIMAL(10,4) NOT NULL DEFAULT 0.0100');
            }

            if (Schema::hasColumn('tenant_billing_snapshots', 'billable_conversation_cost')) {
                DB::statement('ALTER TABLE tenant_billing_snapshots MODIFY billable_conversation_cost DECIMAL(10,4) NOT NULL DEFAULT 0.0000');
            }

            if (Schema::hasColumn('tenant_billing_snapshots', 'template_cost_total')) {
                DB::statement('ALTER TABLE tenant_billing_snapshots MODIFY template_cost_total DECIMAL(10,4) NOT NULL DEFAULT 0.0000');
            }

            if (Schema::hasColumn('tenant_billing_snapshots', 'total_estimated_cost')) {
                DB::statement('ALTER TABLE tenant_billing_snapshots MODIFY total_estimated_cost DECIMAL(10,4) NOT NULL DEFAULT 0.0000');
            }
        }

        if (Schema::hasColumn('message_templates', 'billing_cost')) {
            DB::table('message_templates')
                ->whereNull('billing_cost')
                ->update([
                    'billing_cost' => DB::raw("CASE UPPER(category)
                        WHEN 'MARKETING' THEN 0.0341
                        WHEN 'UTILITY' THEN 0.0091
                        WHEN 'AUTHENTICATION' THEN 0.0091
                        WHEN 'SERVICE' THEN 0.0000
                        ELSE 0.0000
                    END"),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('message_templates', 'billing_cost')) {
            DB::statement('ALTER TABLE message_templates MODIFY billing_cost DECIMAL(10,2) NULL');
        }

        if (Schema::hasColumn('tenant_billing_snapshots', 'billable_window_rate')) {
            DB::statement('ALTER TABLE tenant_billing_snapshots MODIFY billable_window_rate DECIMAL(10,2) NOT NULL DEFAULT 0.01');
        }

        if (Schema::hasColumn('tenant_billing_snapshots', 'billable_conversation_cost')) {
            DB::statement('ALTER TABLE tenant_billing_snapshots MODIFY billable_conversation_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        }

        if (Schema::hasColumn('tenant_billing_snapshots', 'template_cost_total')) {
            DB::statement('ALTER TABLE tenant_billing_snapshots MODIFY template_cost_total DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        }

        if (Schema::hasColumn('tenant_billing_snapshots', 'total_estimated_cost')) {
            DB::statement('ALTER TABLE tenant_billing_snapshots MODIFY total_estimated_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        }
    }
};
