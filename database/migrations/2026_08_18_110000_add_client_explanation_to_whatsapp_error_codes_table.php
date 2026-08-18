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
        Schema::table('whatsapp_error_codes', function (Blueprint $table) {
            $table->text('client_explanation')->nullable()->after('possible_solutions');
            $table->text('client_solution')->nullable()->after('client_explanation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_error_codes', function (Blueprint $table) {
            $table->dropColumn(['client_explanation', 'client_solution']);
        });
    }
};
