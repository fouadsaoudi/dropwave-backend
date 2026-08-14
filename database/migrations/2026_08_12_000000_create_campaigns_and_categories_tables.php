<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Contact Categories Table
        Schema::create('contact_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });

        // 2. Many-to-Many Pivot Table
        Schema::create('contact_category_pivot', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained('contacts')->onDelete('cascade');
            $table->foreignId('category_id')->constrained('contact_categories')->onDelete('cascade');
            $table->primary(['contact_id', 'category_id']);
        });

        // 3. Campaigns Table
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('channel_id')->constrained('waba_channels')->onDelete('cascade');
            $table->foreignId('template_id')->constrained('message_templates')->onDelete('cascade');
            $table->string('name');
            $table->string('status')->default('draft'); // draft, sending, completed, failed
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('total_recipients')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('read_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('blocked_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // 4. Campaign Recipients Table
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->onDelete('cascade');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->onDelete('set null');
            $table->string('phone_number', 30);
            $table->json('variables')->nullable();
            $table->string('status')->default('pending'); // pending, sending, sent, delivered, read, failed, blocked
            $table->string('whatsapp_msg_id', 255)->nullable();
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('campaign_id');
            $table->index('status');
            $table->index('whatsapp_msg_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('contact_category_pivot');
        Schema::dropIfExists('contact_categories');
    }
};
