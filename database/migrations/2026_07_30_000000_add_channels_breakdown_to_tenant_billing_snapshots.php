<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_billing_snapshots') && !Schema::hasColumn('tenant_billing_snapshots', 'channels_breakdown')) {
            Schema::table('tenant_billing_snapshots', function (Blueprint $table) {
                $table->json('channels_breakdown')->nullable()->after('meta_template_breakdown');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_billing_snapshots') && Schema::hasColumn('tenant_billing_snapshots', 'channels_breakdown')) {
            Schema::table('tenant_billing_snapshots', function (Blueprint $table) {
                $table->dropColumn('channels_breakdown');
            });
        }
    }
};
