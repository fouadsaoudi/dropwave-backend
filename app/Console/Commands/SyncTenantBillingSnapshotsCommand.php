<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantBillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncTenantBillingSnapshotsCommand extends Command
{
    protected $signature = 'billing:sync-tenant-snapshots {--month= : Billing month in YYYY-MM format} {--tenant=* : Optional tenant IDs to sync}';

    protected $description = 'Sync monthly billing snapshots for each tenant.';

    public function handle(TenantBillingService $billingService): int
    {
        if (!Schema::hasTable('tenant_billing_snapshots')) {
            $this->warn('tenant_billing_snapshots table is missing. Run migrations before syncing billing snapshots.');
            return self::SUCCESS;
        }

        $monthOption = $this->option('month');
        $month = $monthOption ? Carbon::createFromFormat('Y-m', $monthOption) : now();

        $query = Tenant::query()->orderBy('id');
        $tenantIds = $this->option('tenant');
        if (!empty($tenantIds)) {
            $query->whereIn('id', $tenantIds);
        }

        $count = 0;
        foreach ($query->cursor() as $tenant) {
            $billingService->syncMonthlySnapshot($tenant, $month);
            $count++;
            $this->line("Synced billing snapshot for tenant #{$tenant->id} ({$tenant->name})");
        }

        $this->info("Synced {$count} tenant billing snapshot(s) for {$month->format('F Y')}.");

        return self::SUCCESS;
    }
}
