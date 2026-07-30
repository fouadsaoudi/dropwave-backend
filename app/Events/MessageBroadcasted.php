<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageBroadcasted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Message $message;

    /**
     * Create a new event instance.
     */
    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenants.' . $this->message->tenant_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.broadcasted';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $messageData = $this->message->toArray();

        $conversation = $this->message->conversation 
            ?? \App\Models\Conversation::withoutGlobalScopes()->find($this->message->conversation_id);

        $contact = $conversation?->contact 
            ?? ($conversation ? \App\Models\Contact::withoutGlobalScopes()->find($conversation->contact_id) : null);

        $messageData['contact_name'] = $contact?->name;
        $messageData['contact_phone'] = $contact?->phone_number;

        return [
            'message' => $messageData,
        ];
    }
}
