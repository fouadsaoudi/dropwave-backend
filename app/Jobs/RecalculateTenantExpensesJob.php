<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\TenantBillingService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateTenantExpensesJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $tenantId,
        public ?string $billingMonth = null,
    ) {
    }

    public function handle(TenantBillingService $billingService): void
    {
        $tenant = Tenant::withoutGlobalScopes()->find($this->tenantId);

        if (!$tenant) {
            return;
        }

        $month = $this->billingMonth
            ? Carbon::createFromFormat('Y-m', $this->billingMonth)
            : now();

        $billingService->syncMonthlySnapshot($tenant, $month);
    }
}
