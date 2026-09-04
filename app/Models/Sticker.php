<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToTenant;

class Sticker extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'category',
        'file_path',
        'mime_type',
    ];

    protected $appends = ['url'];

    /**
     * Get the public asset URL for the sticker.
     */
    public function getUrlAttribute(): string
    {
        return asset($this->file_path);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
