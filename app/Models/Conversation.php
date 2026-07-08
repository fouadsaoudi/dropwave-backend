<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToTenant;

class Conversation extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id',
        'contact_id',
        'channel_id',
        'status',
        'assigned_to',
        'assigned_at',
        'resolved_by',
        'resolved_at',
        'window_expires_at',
        'last_message_at',
        'last_message_body',
        'unread_count',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'resolved_at' => 'datetime',
        'window_expires_at' => 'datetime',
        'last_message_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    public function isWindowClosed(): bool
    {
        if (is_null($this->window_expires_at)) {
            return true;
        }
        return $this->window_expires_at->isPast();
    }

    public function isWindowOpen(): bool
    {
        return !$this->isWindowClosed();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(WabaChannel::class, 'channel_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
