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
                if (!Schema::hasColumn('waba_channels', 'typing_indicator_enabled')) {
                    $table->boolean('typing_indicator_enabled')->default(true)->after('calling_enabled');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('waba_channels')) {
            Schema::table('waba_channels', function (Blueprint $table) {
                if (Schema::hasColumn('waba_channels', 'typing_indicator_enabled')) {
                    $table->dropColumn('typing_indicator_enabled');
                }
            });
        }
    }
};
