<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToTenant;

class Contact extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id',
        'phone_number',
        'whatsapp_id',
        'name',
        'avatar_url',
        'added_via',
        'opted_out',
        'opted_out_at',
        'last_seen_at',
    ];

    protected $casts = [
        'opted_out' => 'boolean',
        'opted_out_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }
}
