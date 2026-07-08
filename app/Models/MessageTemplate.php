<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToTenant;

class MessageTemplate extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id',
        'channel_id',
        'name',
        'category',
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
}
