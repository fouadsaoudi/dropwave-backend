<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('waba_channels')) {
            Schema::table('waba_channels', function (Blueprint $table) {
                $table->boolean('calling_enabled')->default(true)->after('is_primary');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('waba_channels')) {
            Schema::table('waba_channels', function (Blueprint $table) {
                $table->dropColumn('calling_enabled');
            });
        }
    }
};
