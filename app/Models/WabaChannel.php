<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToTenant;

class WabaChannel extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id',
        'display_name',
        'phone_number',
        'phone_number_id',
        'waba_id',
        'access_token',
        'quality_rating',
        'messaging_limit',
        'is_active',
        'is_primary',
        'connected_at',
        'token_expires_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
        'connected_at' => 'datetime',
        'token_expires_at' => 'datetime',
    ];

    /**
     * Get decrypted access token.
     */
    public function getDecryptedTokenAttribute(): string
    {
        return decrypt($this->access_token);
    }

    /**
     * Encrypt and set access token.
     */
    public function setAccessTokenAttribute($value)
    {
        $this->attributes['access_token'] = encrypt($value);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'channel_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class, 'channel_id');
    }
}
