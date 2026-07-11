<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToTenant;

class TenantBillingSnapshot extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'billing_month',
        'period_start',
        'period_end',
        'conversation_sessions_count',
        'free_tier_limit',
        'free_tier_remaining',
        'billable_conversations_count',
        'billable_window_rate',
        'billable_conversation_cost',
        'template_cost_total',
        'total_estimated_cost',
        'is_approximate',
        'template_breakdown',
        'calculated_at',
    ];

    protected $casts = [
        'billing_month' => 'date',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'conversation_sessions_count' => 'integer',
        'free_tier_limit' => 'integer',
        'free_tier_remaining' => 'integer',
        'billable_conversations_count' => 'integer',
        'billable_window_rate' => 'decimal:4',
        'billable_conversation_cost' => 'decimal:4',
        'template_cost_total' => 'decimal:4',
        'total_estimated_cost' => 'decimal:4',
        'is_approximate' => 'boolean',
        'template_breakdown' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
