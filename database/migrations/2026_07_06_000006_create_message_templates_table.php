<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('channel_id')->constrained('waba_channels')->onDelete('cascade');
            $table->string('name');
            $table->enum('category', ['UTILITY', 'MARKETING', 'AUTHENTICATION'])->default('UTILITY');
            $table->string('language', 10)->default('en');
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'PAUSED'])->default('PENDING');
            $table->string('meta_template_id', 100)->nullable();
            $table->enum('header_type', ['none', 'text', 'image', 'document', 'video'])->default('none');
            $table->text('header_content')->nullable();
            $table->text('body');
            $table->text('footer')->nullable();
            $table->json('variables')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
