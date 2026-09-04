<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('conversation_id')->constrained('conversations')->onDelete('cascade');
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('whatsapp_call_id', 100)->unique();
            $table->enum('status', ['ringing', 'connected', 'missed', 'busy', 'completed', 'failed'])->default('ringing');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('duration_seconds')->default(0);
            $table->decimal('rate_per_minute', 8, 4)->default(0);
            $table->decimal('cost', 8, 4)->default(0);
            $table->decimal('meta_cost', 8, 4)->default(0);
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
