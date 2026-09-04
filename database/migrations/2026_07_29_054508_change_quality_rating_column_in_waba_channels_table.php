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
        Schema::table('waba_channels', function (Blueprint $table) {
            $table->string('quality_rating', 20)->default('GREEN')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('waba_channels', function (Blueprint $table) {
            $table->enum('quality_rating', ['GREEN', 'YELLOW', 'RED'])->default('GREEN')->change();
        });
    }
};
