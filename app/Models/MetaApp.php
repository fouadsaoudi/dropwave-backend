<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MetaApp extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'app_id',
        'app_secret',
        'verify_token',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'app_secret',
    ];

    protected static function booted(): void
    {
        static::saved(function ($metaApp) {
            static::clearCache($metaApp);
        });

        static::deleted(function ($metaApp) {
            static::clearCache($metaApp);
        });
    }

    /**
     * Clear cached MetaApp lookups and candidate secrets.
     */
    public static function clearCache(?self $metaApp = null): void
    {
        Cache::forget('meta_apps:candidate_secrets');
        if ($metaApp && $metaApp->app_id) {
            Cache::forget("meta_apps:app:{$metaApp->app_id}");
        }
    }

    /**
     * Decrypt the app secret.
     */
    public function getDecryptedAppSecretAttribute(): ?string
    {
        if (empty($this->attributes['app_secret'])) {
            return null;
        }

        try {
            return decrypt($this->attributes['app_secret']);
        } catch (\Exception $e) {
            return $this->attributes['app_secret'];
        }
    }

    /**
     * Encrypt the app secret when setting.
     */
    public function setAppSecretAttribute($value): void
    {
        if (!empty($value)) {
            $this->attributes['app_secret'] = encrypt($value);
        }
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function wabaChannels(): HasMany
    {
        return $this->hasMany(WabaChannel::class, 'meta_app_id');
    }

    /**
     * Find an active MetaApp by App ID (cached in Redis).
     */
    public static function findByAppId(string $appId): ?self
    {
        return Cache::remember("meta_apps:app:{$appId}", 3600, function () use ($appId) {
            return static::where('app_id', $appId)->first();
        });
    }

    /**
     * Get all active app secrets (decrypted) from Redis cache (fallback to DB + config).
     */
    public static function getCandidateSecrets(): array
    {
        return Cache::remember('meta_apps:candidate_secrets', 3600, function () {
            $secrets = [];

            try {
                $apps = static::where('is_active', true)->get();
                foreach ($apps as $app) {
                    $sec = $app->decrypted_app_secret;
                    if ($sec && !in_array($sec, $secrets, true)) {
                        $secrets[] = $sec;
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error fetching MetaApp candidate secrets: ' . $e->getMessage());
            }

            $envSecret = config('services.meta.app_secret');
            if ($envSecret && !in_array($envSecret, $secrets, true)) {
                $secrets[] = $envSecret;
            }

            return $secrets;
        });
    }
}
