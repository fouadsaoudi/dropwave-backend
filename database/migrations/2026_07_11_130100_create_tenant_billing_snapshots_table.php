<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_billing_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->date('billing_month');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->unsignedInteger('conversation_sessions_count')->default(0);
            $table->unsignedInteger('free_tier_limit')->default(1000);
            $table->unsignedInteger('free_tier_remaining')->default(1000);
            $table->unsignedInteger('billable_conversations_count')->default(0);
            $table->decimal('billable_conversation_rate', 10, 2)->default(0.01);
            $table->decimal('billable_conversation_cost', 10, 2)->default(0);
            $table->decimal('template_cost_total', 10, 2)->default(0);
            $table->decimal('total_estimated_cost', 10, 2)->default(0);
            $table->boolean('is_approximate')->default(true);
            $table->json('template_breakdown')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'billing_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_snapshots');
    }
};
