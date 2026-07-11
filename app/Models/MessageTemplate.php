<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToTenant;

class MessageTemplate extends Model
{
    use BelongsToTenant;

    public const DEFAULT_BILLING_COSTS = [
        'MARKETING' => '0.0341',
        'UTILITY' => '0.0091',
        'AUTHENTICATION' => '0.0091',
        'SERVICE' => '0.0000',
    ];

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'name',
        'category',
        'billing_cost',
        'language',
        'status',
        'meta_template_id',
        'header_type',
        'header_content',
        'body',
        'footer',
        'variables',
        'rejection_reason',
        'submitted_at',
        'approved_at',
    ];

    protected $casts = [
        'billing_cost' => 'decimal:4',
        'variables' => 'json',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(WabaChannel::class, 'channel_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'template_id');
    }

    public static function defaultBillingCostForCategory(?string $category): string
    {
        $normalizedCategory = strtoupper(trim((string) $category));

        return self::DEFAULT_BILLING_COSTS[$normalizedCategory] ?? '0.0000';
    }
}
