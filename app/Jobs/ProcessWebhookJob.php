<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\WebhookEvent;
use App\Models\WabaChannel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class ProcessWebhookJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected int $eventId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $eventId)
    {
        $this->eventId = $eventId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $event = WebhookEvent::find($this->eventId);
        if (!$event || $event->processed) {
            return;
        }

        try {
            $payload = $event->payload;
            $entry = $payload['entry'][0] ?? null;
            $change = $entry['changes'][0] ?? null;
            $value = $change['value'] ?? null;

            if (!$value) {
                $event->update([
                    'processed' => true,
                    'processed_at' => now(),
                ]);
                return;
            }

            // Identify WABA channel phone number id
            $phoneId = $value['metadata']['phone_number_id'] ?? null;
            if (!$phoneId) {
                $event->update([
                    'processed' => true,
                    'processed_at' => now(),
                ]);
                return;
            }

            // Find the WABA Channel
            $channel = WabaChannel::withoutGlobalScopes()->where('phone_number_id', $phoneId)->first();
            if (!$channel) {
                throw new Exception("WABA Channel not configured in system for phone ID: " . $phoneId);
            }

            // Link event to tenant
            $event->update(['tenant_id' => $channel->tenant_id]);

            // Process Inbound Messages
            if (isset($value['messages']) && is_array($value['messages'])) {
                foreach ($value['messages'] as $msg) {
                    $this->processInboundMessage($msg, $value['contacts'] ?? [], $channel);
                }
            }

            // Process Status Updates
            if (isset($value['statuses']) && is_array($value['statuses'])) {
                foreach ($value['statuses'] as $status) {
                    $this->processMessageStatusUpdate($status);
                }
            }

            $event->update([
                'processed' => true,
                'processed_at' => now(),
            ]);

        } catch (Exception $e) {
            Log::error('Error processing webhook event: ' . $e->getMessage(), [
                'event_id' => $this->eventId,
                'trace' => $e->getTraceAsString()
            ]);

            $event->update([
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Process an inbound message block.
     */
    protected function processInboundMessage(array $msg, array $contactsPayload, WabaChannel $channel): void
    {
        $fromNumber = '+' . ltrim($msg['from'], '+');
        $waId = $msg['from'];
        $msgId = $msg['id'];
        $timestamp = Carbon::createFromTimestamp($msg['timestamp']);

        // Extract contact profile name
        $contactName = $fromNumber;
        foreach ($contactsPayload as $c) {
            if ($c['wa_id'] === $waId) {
                $contactName = $c['profile']['name'] ?? $fromNumber;
                break;
            }
        }

        DB::transaction(function () use ($channel, $fromNumber, $waId, $msgId, $timestamp, $contactName, $msg) {
            // 1. Resolve Contact
            $contact = Contact::withoutGlobalScopes()->where([
                'tenant_id' => $channel->tenant_id,
                'phone_number' => $fromNumber,
            ])->first();

            if (!$contact) {
                $contact = Contact::create([
                    'tenant_id' => $channel->tenant_id,
                    'phone_number' => $fromNumber,
                    'whatsapp_id' => $waId,
                    'name' => null, // Show phone number by default until agent decides to update it
                    // 'name' => $contactName, // COMMENTED OUT: Option to auto-populate from WhatsApp profile name
                    'last_seen_at' => $timestamp,
                ]);
            } else {
                $contact->update([
                    'whatsapp_id' => $waId,
                    'last_seen_at' => $timestamp,
                    // 'name' => $contactName, // COMMENTED OUT: Do not overwrite manual contact names on new messages
                ]);
            }

            // 2. Resolve Conversation (open or closed)
            $conversation = Conversation::withoutGlobalScopes()->where([
                'tenant_id' => $channel->tenant_id,
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
            ])->whereIn('status', ['open', 'resolved'])->first();

            $lastMessageBody = $this->getMessagePreviewBody($msg);

            if (!$conversation) {
                $conversation = Conversation::withoutGlobalScopes()->create([
                    'tenant_id' => $channel->tenant_id,
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'status' => 'open',
                    'window_expires_at' => $timestamp->copy()->addHours(24),
                    'last_message_at' => $timestamp,
                    'last_message_body' => $lastMessageBody,
                    'unread_count' => 1,
                ]);
            } else {
                $conversation->update([
                    'status' => 'open', // Re-open tickets automatically on client incoming messages
                    'window_expires_at' => $timestamp->copy()->addHours(24),
                    'last_message_at' => $timestamp,
                    'last_message_body' => $lastMessageBody,
                    'unread_count' => $conversation->unread_count + 1,
                ]);
            }

            // 3. Save Message
            $type = $msg['type'] ?? 'text';
            $body = $msg['text']['body'] ?? null;
            $lat = $msg['location']['latitude'] ?? null;
            $lng = $msg['location']['longitude'] ?? null;

            Message::withoutGlobalScopes()->create([
                'tenant_id' => $channel->tenant_id,
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'type' => $type,
                'body' => $body,
                'latitude' => $lat,
                'longitude' => $lng,
                'whatsapp_msg_id' => $msgId,
                'status' => 'delivered', // Incoming is delivered to us
                'sent_at' => $timestamp,
            ]);

            // TODO: Dispatch download media job if media fields are set
            // TODO: Broadcast Echo/Reverb NewMessageReceived event
        });
    }

    /**
     * Process message status update events.
     */
    protected function processMessageStatusUpdate(array $status): void
    {
        $msgId = $status['id'];
        $newStatus = $status['status'];
        $timestamp = Carbon::createFromTimestamp($status['timestamp']);

        $message = Message::withoutGlobalScopes()
            ->where('whatsapp_msg_id', $msgId)
            ->first();

        if (!$message) {
            return;
        }

        // Define status progression hierarchy weights
        $statusWeights = [
            'failed' => -1,
            'sent' => 1,
            'delivered' => 2,
            'read' => 3,
        ];

        $currentStatusWeight = $statusWeights[$message->status] ?? 0;
        $newStatusWeight = $statusWeights[$newStatus] ?? 0;

        $updateData = [];
        // Only progress the status string forward, never regress
        if ($newStatusWeight > $currentStatusWeight) {
            $updateData['status'] = $newStatus;
        }

        if ($newStatus === 'sent') {
            $updateData['sent_at'] = $timestamp;
        } elseif ($newStatus === 'delivered') {
            $updateData['delivered_at'] = $timestamp;
        } elseif ($newStatus === 'read') {
            $updateData['read_at'] = $timestamp;
        } elseif ($newStatus === 'failed') {
            $errors = $status['errors'][0] ?? null;
            if ($errors) {
                $updateData['error_code'] = $errors['code'] ?? null;
                $updateData['error_message'] = $errors['message'] ?? null;
            }
        }

        if (!empty($updateData)) {
            $message->update($updateData);
        }

        // TODO: Broadcast Echo/Reverb MessageStatusUpdated event
    }

    /**
     * Parse message fields to provide a text preview string.
     */
    protected function getMessagePreviewBody(array $msg): string
    {
        $type = $msg['type'] ?? 'text';
        switch ($type) {
            case 'text':
                return substr($msg['text']['body'] ?? '', 0, 50);
            case 'image':
                return '📷 Photo';
            case 'audio':
            case 'voice':
                return '🎵 Voice Note';
            case 'video':
                return '🎥 Video';
            case 'document':
                return '📄 Document';
            case 'location':
                return '📍 Location';
            case 'sticker':
                return '✨ Sticker';
            case 'reaction':
                return '❤️ Reaction';
            default:
                return '✉️ Message';
        }
    }
}
