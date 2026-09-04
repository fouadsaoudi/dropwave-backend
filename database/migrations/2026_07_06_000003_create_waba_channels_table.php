<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waba_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('display_name');
            $table->string('phone_number', 20);
            $table->string('phone_number_id', 100);
            $table->string('waba_id', 100);
            $table->text('access_token'); // stored encrypted
            $table->enum('quality_rating', ['GREEN', 'YELLOW', 'RED'])->default('GREEN');
            $table->string('messaging_limit', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('token_expires_at')->nullable(); // for future use/extensions
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waba_channels');
    }
};
