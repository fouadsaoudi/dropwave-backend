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
        Schema::create('tenant_ai_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->date('usage_date');
            $table->unsignedInteger('requests_count')->default(0);
            $table->unsignedInteger('daily_limit')->default(50);
            $table->timestamp('last_request_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'usage_date']);
            $table->index(['tenant_id', 'usage_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_usages');
    }
};
