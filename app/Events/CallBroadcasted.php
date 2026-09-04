<?php

namespace App\Events;

use App\Models\Call;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallBroadcasted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Call $call;
    public ?array $webrtcSession;

    /**
     * Create a new event instance.
     */
    public function __construct(Call $call, ?array $webrtcSession = null)
    {
        $this->call = $call;
        $this->webrtcSession = $webrtcSession;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tenants.' . $this->call->tenant_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'call.broadcasted';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $callData = $this->call->toArray();

        $conversation = $this->call->conversation
            ?? \App\Models\Conversation::withoutGlobalScopes()->find($this->call->conversation_id);

        $contact = $conversation?->contact
            ?? ($conversation ? \App\Models\Contact::withoutGlobalScopes()->find($conversation->contact_id) : null);

        $callData['contact_name'] = $contact?->name;
        $callData['contact_phone'] = $contact?->phone_number;

        return [
            'call' => $callData,
            'webrtc_session' => $this->webrtcSession,
        ];
    }
}
