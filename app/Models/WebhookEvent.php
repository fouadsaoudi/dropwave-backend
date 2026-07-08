<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToTenant;

class WebhookEvent extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id',
        'event_type',
        'payload',
        'processed',
        'processed_at',
        'error',
    ];

    protected $casts = [
        'payload' => 'json',
        'processed' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
