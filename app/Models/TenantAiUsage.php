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
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'estimated_cost',
        'last_request_at',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'requests_count' => 'integer',
        'daily_limit' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'estimated_cost' => 'decimal:6',
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
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'estimated_cost' => 0.000000,
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
     * Increment usage count and accumulate token stats for today.
     */
    public static function recordAudit(int $tenantId, array $tokens = [], int $defaultLimit = self::DEFAULT_DAILY_LIMIT): self
    {
        $usage = self::forTenantToday($tenantId, $defaultLimit);
        $usage->requests_count += 1;
        $usage->prompt_tokens += (int) ($tokens['prompt_tokens'] ?? 0);
        $usage->completion_tokens += (int) ($tokens['completion_tokens'] ?? 0);
        $usage->total_tokens += (int) ($tokens['total_tokens'] ?? 0);
        $usage->estimated_cost += (float) ($tokens['estimated_cost'] ?? 0.000000);
        $usage->last_request_at = Carbon::now();
        $usage->save();

        return $usage;
    }

    /**
     * Get a comprehensive usage summary for a tenant including tokens and estimated costs.
     */
    public static function getTenantSummary(int $tenantId, int $defaultLimit = self::DEFAULT_DAILY_LIMIT): array
    {
        $todayRecord = self::forTenantToday($tenantId, $defaultLimit);
        $todayUsed = $todayRecord->requests_count;
        $dailyLimit = $todayRecord->daily_limit;
        $remaining = max(0, $dailyLimit - $todayUsed);

        $lifetimeAudits = (int) self::where('tenant_id', $tenantId)->sum('requests_count');
        $lifetimePromptTokens = (int) self::where('tenant_id', $tenantId)->sum('prompt_tokens');
        $lifetimeCompletionTokens = (int) self::where('tenant_id', $tenantId)->sum('completion_tokens');
        $lifetimeTotalTokens = (int) self::where('tenant_id', $tenantId)->sum('total_tokens');
        $lifetimeCost = (float) self::where('tenant_id', $tenantId)->sum('estimated_cost');

        $lastRequest = self::where('tenant_id', $tenantId)->whereNotNull('last_request_at')->max('last_request_at');

        return [
            'tenant_id' => $tenantId,
            'today_used' => $todayUsed,
            'daily_limit' => $dailyLimit,
            'remaining_today' => $remaining,
            'today_prompt_tokens' => (int) $todayRecord->prompt_tokens,
            'today_completion_tokens' => (int) $todayRecord->completion_tokens,
            'today_total_tokens' => (int) $todayRecord->total_tokens,
            'today_estimated_cost' => number_format((float) $todayRecord->estimated_cost, 6, '.', ''),
            'lifetime_audits' => $lifetimeAudits,
            'lifetime_prompt_tokens' => $lifetimePromptTokens,
            'lifetime_completion_tokens' => $lifetimeCompletionTokens,
            'lifetime_total_tokens' => $lifetimeTotalTokens,
            'lifetime_estimated_cost' => number_format($lifetimeCost, 6, '.', ''),
            'last_request_at' => $lastRequest,
            'is_limit_reached' => $remaining <= 0,
        ];
    }
}
