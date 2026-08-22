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
        Schema::table('tenant_ai_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('prompt_tokens')->default(0)->after('daily_limit');
            $table->unsignedBigInteger('completion_tokens')->default(0)->after('prompt_tokens');
            $table->unsignedBigInteger('total_tokens')->default(0)->after('completion_tokens');
            $table->decimal('estimated_cost', 10, 6)->default(0.000000)->after('total_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_ai_usages', function (Blueprint $table) {
            $table->dropColumn(['prompt_tokens', 'completion_tokens', 'total_tokens', 'estimated_cost']);
        });
    }
};
