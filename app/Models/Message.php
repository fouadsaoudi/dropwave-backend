<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToTenant;

class Message extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'call_id',
        'direction',
        'type',
        'body',
        'media_url',
        'media_filename',
        'media_mime_type',
        'latitude',
        'longitude',
        'template_id',
        'reaction_emoji',
        'reaction_to_msg_id',
        'whatsapp_msg_id',
        'is_internal',
        'status',
        'error_code',
        'error_message',
        'sent_by',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $appends = ['media_proxy_url'];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_internal' => 'boolean',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function getMediaProxyUrlAttribute(): ?string
    {
        if (!$this->media_url) {
            return null;
        }

        $hash = hash_hmac('sha256', $this->id, config('app.key'));
        return "/api/media/proxy?message_id={$this->id}&hash={$hash}";
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class, 'call_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function reactionTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reaction_to_msg_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(Message::class, 'reaction_to_msg_id');
    }
}
