<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class TenantAiUsage extends Model
{
    public const DEFAULT_DAILY_LIMIT = 50;

    protected $fillable = [
        'tenant_id',
        'usage_date',
        'requests_count',
        'daily_limit',
        'last_request_at',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'requests_count' => 'integer',
        'daily_limit' => 'integer',
        'last_request_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get or create the usage record for a tenant for today.
     */
    public static function forTenantToday(int $tenantId, int $defaultLimit = self::DEFAULT_DAILY_LIMIT): self
    {
        $today = Carbon::today()->toDateString();

        return self::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'usage_date' => $today,
            ],
            [
                'requests_count' => 0,
                'daily_limit' => $defaultLimit,
            ]
        );
    }

    /**
     * Check if tenant has remaining AI audits today.
     */
    public static function canAudit(int $tenantId, int $defaultLimit = self::DEFAULT_DAILY_LIMIT): bool
    {
        $usage = self::forTenantToday($tenantId, $defaultLimit);

        return $usage->requests_count < $usage->daily_limit;
    }

    /**
     * Increment usage count for today.
     */
    public static function recordAudit(int $tenantId, int $defaultLimit = self::DEFAULT_DAILY_LIMIT): self
    {
        $usage = self::forTenantToday($tenantId, $defaultLimit);
        $usage->requests_count += 1;
        $usage->last_request_at = Carbon::now();
        $usage->save();

        return $usage;
    }

    /**
     * Get a comprehensive usage summary for a tenant.
     */
    public static function getTenantSummary(int $tenantId, int $defaultLimit = self::DEFAULT_DAILY_LIMIT): array
    {
        $todayRecord = self::forTenantToday($tenantId, $defaultLimit);
        $todayUsed = $todayRecord->requests_count;
        $dailyLimit = $todayRecord->daily_limit;
        $remaining = max(0, $dailyLimit - $todayUsed);

        $lifetimeAudits = (int) self::where('tenant_id', $tenantId)->sum('requests_count');
        $lastRequest = self::where('tenant_id', $tenantId)->whereNotNull('last_request_at')->max('last_request_at');

        return [
            'tenant_id' => $tenantId,
            'today_used' => $todayUsed,
            'daily_limit' => $dailyLimit,
            'remaining_today' => $remaining,
            'lifetime_audits' => $lifetimeAudits,
            'last_request_at' => $lastRequest,
            'is_limit_reached' => $remaining <= 0,
        ];
    }
}
