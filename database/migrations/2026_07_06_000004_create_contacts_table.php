<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('phone_number', 20); // E.164
            $table->string('whatsapp_id', 50)->nullable();
            $table->string('name')->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->enum('added_via', ['inbound', 'csv_upload', 'manual'])->default('manual');
            $table->boolean('opted_out')->default(false);
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
