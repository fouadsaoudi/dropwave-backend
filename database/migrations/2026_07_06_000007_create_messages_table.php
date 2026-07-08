<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('conversation_id')->constrained('conversations')->onDelete('cascade');
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('type', [
                'text', 'image', 'audio', 'video', 'document',
                'location', 'sticker', 'reaction', 'template', 'unsupported'
            ])->default('text');
            $table->text('body')->nullable();
            $table->string('media_url', 500)->nullable();
            $table->string('media_filename', 255)->nullable();
            $table->string('media_mime_type', 100)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->foreignId('template_id')->nullable()->constrained('message_templates')->onDelete('set null');
            $table->string('reaction_emoji', 10)->nullable();
            $table->foreignId('reaction_to_msg_id')->nullable()->constrained('messages')->onDelete('set null');
            $table->string('whatsapp_msg_id', 100)->nullable()->unique();
            $table->enum('status', ['pending', 'sent', 'delivered', 'read', 'failed'])->default('pending');
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
