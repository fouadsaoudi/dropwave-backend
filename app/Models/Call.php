<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToTenant;

class Call extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'direction',
        'whatsapp_call_id',
        'status',
        'started_at',
        'ended_at',
        'duration_seconds',
        'rate_per_minute',
        'cost',
        'meta_cost',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
        'rate_per_minute' => 'decimal:4',
        'cost' => 'decimal:4',
        'meta_cost' => 'decimal:4',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Compute call cost based on duration and rate_per_minute.
     * Round up duration to the next 6-second block (pulse).
     * Billed rate for each 6-second block is 1/10th of per-minute rate.
     */
    public function calculateCost(): float
    {
        if ($this->direction === 'inbound') {
            return 0.0000;
        }

        if ($this->duration_seconds <= 0) {
            return 0.0000;
        }

        $pulses = ceil($this->duration_seconds / 6);
        $costPerPulse = $this->rate_per_minute / 10;

        return (float) ($pulses * $costPerPulse);
    }
}
