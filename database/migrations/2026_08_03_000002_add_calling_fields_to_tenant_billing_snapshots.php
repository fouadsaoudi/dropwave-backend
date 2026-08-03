<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_billing_snapshots')) {
            Schema::table('tenant_billing_snapshots', function (Blueprint $table) {
                $table->decimal('call_cost_total', 12, 4)->default(0.0000)->after('template_cost_total');
                $table->decimal('meta_call_cost_total', 12, 4)->default(0.0000)->after('meta_template_cost_total');
                $table->json('calls_breakdown')->nullable()->after('channels_breakdown');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_billing_snapshots')) {
            Schema::table('tenant_billing_snapshots', function (Blueprint $table) {
                $table->dropColumn(['call_cost_total', 'meta_call_cost_total', 'calls_breakdown']);
            });
        }
    }
};
