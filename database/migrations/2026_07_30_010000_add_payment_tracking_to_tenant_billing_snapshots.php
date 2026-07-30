<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_billing_snapshots')) {
            Schema::table('tenant_billing_snapshots', function (Blueprint $table) {
                if (!Schema::hasColumn('tenant_billing_snapshots', 'payment_status')) {
                    $table->enum('payment_status', ['unpaid', 'paid'])->default('unpaid')->after('channels_breakdown');
                }
                if (!Schema::hasColumn('tenant_billing_snapshots', 'paid_at')) {
                    $table->timestamp('paid_at')->nullable()->after('payment_status');
                }
                if (!Schema::hasColumn('tenant_billing_snapshots', 'amount_paid')) {
                    $table->decimal('amount_paid', 10, 4)->default(0.0000)->after('paid_at');
                }
                if (!Schema::hasColumn('tenant_billing_snapshots', 'payment_notes')) {
                    $table->text('payment_notes')->nullable()->after('amount_paid');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_billing_snapshots')) {
            Schema::table('tenant_billing_snapshots', function (Blueprint $table) {
                $columnsToDrop = [];
                if (Schema::hasColumn('tenant_billing_snapshots', 'payment_status')) {
                    $columnsToDrop[] = 'payment_status';
                }
                if (Schema::hasColumn('tenant_billing_snapshots', 'paid_at')) {
                    $columnsToDrop[] = 'paid_at';
                }
                if (Schema::hasColumn('tenant_billing_snapshots', 'amount_paid')) {
                    $columnsToDrop[] = 'amount_paid';
                }
                if (Schema::hasColumn('tenant_billing_snapshots', 'payment_notes')) {
                    $columnsToDrop[] = 'payment_notes';
                }
                if (!empty($columnsToDrop)) {
                    $table->dropColumn($columnsToDrop);
                }
            });
        }
    }
};
