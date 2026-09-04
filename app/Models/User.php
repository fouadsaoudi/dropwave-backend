<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role_id',
        'is_active',
        'last_seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_to');
    }

    public function fcmTokens(): HasMany
    {
        return $this->hasMany(UserFcmToken::class);
    }

    public function isAdmin(): bool
    {
        return $this->role?->name === 'admin';
    }

    public function isAgent(): bool
    {
        return $this->role?->name === 'agent';
    }

    public function isManager(): bool
    {
        return $this->role?->name === 'manager';
    }
}
