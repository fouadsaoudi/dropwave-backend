<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\TenantBillingSnapshot;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'contact_name',
        'email',
        'phone',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function isDeliveryCoordination(): bool
    {
        return $this->type === 'delivery_coordination';
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(WabaChannel::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function billingSnapshots(): HasMany
    {
        return $this->hasMany(TenantBillingSnapshot::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
