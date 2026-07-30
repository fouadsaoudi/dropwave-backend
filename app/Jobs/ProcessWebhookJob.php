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
            $phoneId = $value['metadata']['phone_number_id'] ?? $value['phone_number_id'] ?? null;
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

            // Process Phone Number Quality Updates
            $field = $change['field'] ?? null;
            if ($field === 'phone_number_quality_update') {
                $newRating = $value['new_quality_rating'] ?? null;
                if ($newRating && in_array(strtoupper($newRating), ['GREEN', 'YELLOW', 'RED', 'UNKNOWN'])) {
                    $oldRating = $channel->quality_rating;
                    $newRatingUpper = strtoupper($newRating);

                    if ($oldRating !== $newRatingUpper) {
                        $channel->update([
                            'quality_rating' => $newRatingUpper
                        ]);
                        Log::info("WABA Channel {$channel->id} quality rating updated to {$newRatingUpper} via webhook.");

                        // If rating turned to YELLOW or RED, send alert email
                        if ($newRatingUpper === 'YELLOW' || $newRatingUpper === 'RED') {
                            try {
                                $adminEmail = env('ADMIN_ALERT_EMAIL', 'fouad.saoudi94@gmail.com');
                                \Illuminate\Support\Facades\Mail::to($adminEmail)->send(
                                    new \App\Mail\ChannelQualityWarningMail($channel, $oldRating, $newRatingUpper)
                                );
                                Log::info("Channel quality warning email sent to {$adminEmail}");
                            } catch (Exception $e) {
                                Log::error("Failed to send quality warning email: " . $e->getMessage());
                            }
                        }
                    }
                }
            }

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

            // Check if the webhook message is stale (sent more than 24 hours ago)
            $isStale = $timestamp->copy()->addHours(24)->isBefore(Carbon::now());

            if (!$conversation) {
                $conversation = Conversation::withoutGlobalScopes()->create([
                    'tenant_id' => $channel->tenant_id,
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'status' => $isStale ? 'resolved' : 'open',
                    'window_expires_at' => $timestamp->copy()->addHours(24),
                    'last_message_at' => $timestamp,
                    'last_message_body' => $lastMessageBody,
                    'unread_count' => $isStale ? 0 : 1,
                ]);
            } else {
                $updateData = [
                    'window_expires_at' => $timestamp->copy()->addHours(24),
                    'last_message_at' => $timestamp,
                    'last_message_body' => $lastMessageBody,
                ];
                if (!$isStale) {
                    $updateData['status'] = 'open';
                    $updateData['unread_count'] = $conversation->unread_count + 1;
                }
                $conversation->update($updateData);
            }

            // 3. Save Message
            $type = $msg['type'] ?? 'text';
            $body = $msg['text']['body'] ?? null;
            $lat = $msg['location']['latitude'] ?? null;
            $lng = $msg['location']['longitude'] ?? null;

            $mediaUrl = null;
            $mediaMimeType = null;
            $mediaFilename = null;

            // Handle captions for media types
            if (!$body && in_array($type, ['image', 'video', 'document'])) {
                $body = $msg[$type]['caption'] ?? null;
            }

            // Extract media files metadata
            if (in_array($type, ['image', 'video', 'audio', 'document', 'sticker'])) {
                $mediaObj = $msg[$type] ?? null;
                if ($mediaObj) {
                    $mediaUrl = $mediaObj['url'] ?? null;
                    $mediaMimeType = $mediaObj['mime_type'] ?? null;
                    $mediaFilename = $mediaObj['filename'] ?? $mediaObj['id'] ?? null;
                }
            }

            $message = Message::withoutGlobalScopes()->create([
                'tenant_id' => $channel->tenant_id,
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'type' => $type,
                'body' => $body,
                'media_url' => $mediaUrl,
                'media_mime_type' => $mediaMimeType,
                'media_filename' => $mediaFilename,
                'latitude' => $lat,
                'longitude' => $lng,
                'whatsapp_msg_id' => $msgId,
                'status' => 'delivered', // Incoming is delivered to us
                'sent_at' => $timestamp,
            ]);

            // 4. Create Notification
            if (!$isStale) {
                $displaySender = ($contact->name && $contact->name !== $fromNumber)
                    ? "{$contact->name} ({$fromNumber})"
                    : $fromNumber;

                \App\Models\Notification::withoutGlobalScopes()->create([
                    'tenant_id' => $channel->tenant_id,
                    'sender' => $displaySender,
                    'message_body' => $lastMessageBody,
                    'conversation_id' => $conversation->id,
                    'is_read' => false,
                ]);
            }

            // Broadcast Echo/Reverb events
            broadcast(new \App\Events\MessageBroadcasted($message));
            broadcast(new \App\Events\ConversationUpdated($conversation));
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
        // Only progress the status string forward, never regress, unless failed (terminal state)
        if ($newStatus === 'failed' || $newStatusWeight > $currentStatusWeight) {
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
            broadcast(new \App\Events\MessageBroadcasted($message));
        }
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
