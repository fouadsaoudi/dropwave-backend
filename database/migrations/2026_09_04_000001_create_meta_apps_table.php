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
        if (!Schema::hasTable('meta_apps')) {
            Schema::create('meta_apps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $table->string('name')->nullable();
                $table->string('app_id', 100)->unique();
                $table->text('app_secret'); // encrypted via Laravel Crypt
                $table->string('verify_token')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('app_id');
            });
        }

        if (Schema::hasTable('waba_channels') && !Schema::hasColumn('waba_channels', 'meta_app_id')) {
            Schema::table('waba_channels', function (Blueprint $table) {
                $table->foreignId('meta_app_id')->nullable()->after('tenant_id')->constrained('meta_apps')->nullOnDelete();
                $table->index('meta_app_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('waba_channels') && Schema::hasColumn('waba_channels', 'meta_app_id')) {
            Schema::table('waba_channels', function (Blueprint $table) {
                $table->dropForeign(['meta_app_id']);
                $table->dropColumn('meta_app_id');
            });
        }

        Schema::dropIfExists('meta_apps');
    }
};
