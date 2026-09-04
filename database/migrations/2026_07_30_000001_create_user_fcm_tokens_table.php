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
        // Remove single fcm_token column from users table if it exists
        if (Schema::hasColumn('users', 'fcm_token')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('fcm_token');
            });
        }

        Schema::create('user_fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('fcm_token', 500)->unique();
            $table->string('device_type')->nullable(); // 'android', 'ios', 'web'
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'fcm_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_fcm_tokens');
        Schema::table('users', function (Blueprint $table) {
            $table->string('fcm_token')->nullable();
        });
    }
};
