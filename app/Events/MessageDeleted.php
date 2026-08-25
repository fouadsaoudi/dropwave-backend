<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $messageId;
    public ?string $whatsappMsgId;
    public int $conversationId;
    public int $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(int $messageId, ?string $whatsappMsgId, int $conversationId, int $tenantId)
    {
        $this->messageId = $messageId;
        $this->whatsappMsgId = $whatsappMsgId;
        $this->conversationId = $conversationId;
        $this->tenantId = $tenantId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenants.' . $this->tenantId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'whatsapp_msg_id' => $this->whatsappMsgId,
            'conversation_id' => $this->conversationId,
        ];
    }
}
