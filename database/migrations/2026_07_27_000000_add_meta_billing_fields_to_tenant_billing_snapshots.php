<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_billing_snapshots', function (Blueprint $table) {
            $table->decimal('meta_billable_window_rate', 10, 4)->default(0.0100);
            $table->decimal('meta_billable_conversation_cost', 10, 4)->default(0.0000);
            $table->decimal('meta_template_cost_total', 10, 4)->default(0.0000);
            $table->decimal('meta_total_estimated_cost', 10, 4)->default(0.0000);
            $table->json('meta_template_breakdown')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_billing_snapshots', function (Blueprint $table) {
            $table->dropColumn([
                'meta_billable_window_rate',
                'meta_billable_conversation_cost',
                'meta_template_cost_total',
                'meta_total_estimated_cost',
                'meta_template_breakdown',
            ]);
        });
    }
};
