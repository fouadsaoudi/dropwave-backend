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

    public function categories(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ContactCategory::class, 'contact_category_pivot', 'contact_id', 'category_id');
    }

    /**
     * Get the associated driver (if any) by phone number.
     */
    public function driver(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Driver::class, 'phone_number', 'phone_number');
    }

    /**
     * Fallback to driver name if contact name is empty.
     */
    public function getNameAttribute($value)
    {
        if (empty($value) && $this->relationLoaded('driver') && $this->driver) {
            return $this->driver->name;
        }
        return $value;
    }
}

