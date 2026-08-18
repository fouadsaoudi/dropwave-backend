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
use Illuminate\Support\Facades\Http;

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

            // Process Call Events
            if ($field === 'calls' || (isset($value['calls']) && is_array($value['calls']))) {
                foreach ($value['calls'] as $callData) {
                    $this->processCallEvent($callData, $channel);
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

            // Handle WhatsApp reaction webhook
            if ($type === 'reaction') {
                $targetWaMsgId = $msg['reaction']['message_id'] ?? null;
                $reactionEmoji = $msg['reaction']['emoji'] ?? '';

                $targetMessage = $targetWaMsgId ? Message::withoutGlobalScopes()
                    ->where('tenant_id', $channel->tenant_id)
                    ->where('whatsapp_msg_id', $targetWaMsgId)
                    ->first() : null;

                if (empty($reactionEmoji)) {
                    // Reaction removed
                    if ($targetMessage) {
                        Message::withoutGlobalScopes()
                            ->where('conversation_id', $conversation->id)
                            ->where('type', 'reaction')
                            ->where('direction', 'inbound')
                            ->where('reaction_to_msg_id', $targetMessage->id)
                            ->delete();
                    }
                } else {
                    $existingReaction = $targetMessage ? Message::withoutGlobalScopes()
                        ->where('conversation_id', $conversation->id)
                        ->where('type', 'reaction')
                        ->where('direction', 'inbound')
                        ->where('reaction_to_msg_id', $targetMessage->id)
                        ->first() : null;

                    if ($existingReaction) {
                        $existingReaction->update([
                            'reaction_emoji' => $reactionEmoji,
                            'whatsapp_msg_id' => $msgId,
                            'status' => 'delivered',
                            'sent_at' => $timestamp,
                        ]);
                    } else {
                        Message::withoutGlobalScopes()->create([
                            'tenant_id' => $channel->tenant_id,
                            'conversation_id' => $conversation->id,
                            'direction' => 'inbound',
                            'type' => 'reaction',
                            'reaction_emoji' => $reactionEmoji,
                            'reaction_to_msg_id' => $targetMessage?->id,
                            'whatsapp_msg_id' => $msgId,
                            'status' => 'delivered',
                            'sent_at' => $timestamp,
                        ]);
                    }
                }

                if ($targetMessage) {
                    $targetMessage->load(['sender', 'reactions.sender']);
                    broadcast(new \App\Events\MessageBroadcasted($targetMessage));
                }
                broadcast(new \App\Events\ConversationUpdated($conversation));
                return;
            }

            $body = $msg['text']['body'] ?? null;
            $lat = $msg['location']['latitude'] ?? null;
            $lng = $msg['location']['longitude'] ?? null;

            if ($type === 'location') {
                $locationData = $msg['location'] ?? [];
                $locName = $locationData['name'] ?? null;
                $locAddress = $locationData['address'] ?? null;
                if ($locName && $locAddress) {
                    $body = "{$locName}\n{$locAddress}";
                } elseif ($locName) {
                    $body = $locName;
                } elseif ($locAddress) {
                    $body = $locAddress;
                } else {
                    $body = "Shared location: Lat {$lat}, Lng {$lng}";
                }
            }

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
                    $mediaId = $mediaObj['id'] ?? null;

                    if ($mediaId && $channel->decrypted_token) {
                        try {
                            $disk = config('filesystems.media_disk', 'public');
                            $apiVersion = config('services.meta.api_version', 'v20.0');
                            $metaUrl = "https://graph.facebook.com/{$apiVersion}/{$mediaId}";

                            $metaResponse = Http::timeout(30)->connectTimeout(10)->withHeaders([
                                'Authorization' => 'Bearer ' . $channel->decrypted_token
                            ])->get($metaUrl);

                            if ($metaResponse->successful()) {
                                $downloadUrl = $metaResponse->json('url');
                                if ($downloadUrl) {
                                    $extension = 'bin';
                                    if ($mediaMimeType) {
                                        $mimeParts = explode('/', $mediaMimeType);
                                        if (count($mimeParts) === 2) {
                                            $extension = $mimeParts[1];
                                            if (str_contains($extension, ';')) {
                                                $extension = explode(';', $extension)[0];
                                            }
                                        }
                                    }

                                    if ($extension === 'jpeg') {
                                        $extension = 'jpg';
                                    }
                                    if ($mediaMimeType === 'audio/ogg' || $mediaMimeType === 'audio/ogg; codecs=opus') {
                                        $extension = 'ogg';
                                    }

                                    $fileName = $mediaId . '.' . $extension;
                                    $storedFolder = 'conversations/' . $conversation->id;
                                    $relativePath = $storedFolder . '/' . $fileName;
                                    $tempPath = storage_path('app/temp_' . uniqid() . '.' . $extension);

                                    $downloadResponse = Http::timeout(300)
                                        ->connectTimeout(15)
                                        ->withOptions(['sink' => $tempPath])
                                        ->withHeaders([
                                            'Authorization' => 'Bearer ' . $channel->decrypted_token
                                        ])->get($downloadUrl);

                                    if ($downloadResponse->successful()) {
                                        if (file_exists($tempPath) && filesize($tempPath) > 0) {
                                            $stream = fopen($tempPath, 'r');
                                            \Illuminate\Support\Facades\Storage::disk($disk)->put($relativePath, $stream);
                                            if (is_resource($stream)) {
                                                fclose($stream);
                                            }
                                            @unlink($tempPath);
                                        } else {
                                            \Illuminate\Support\Facades\Storage::disk($disk)->put($relativePath, $downloadResponse->body());
                                        }

                                        $mediaUrl = 'storage/' . $relativePath;
                                    } else {
                                        if (file_exists($tempPath)) {
                                            @unlink($tempPath);
                                        }
                                        Log::warning("ProcessWebhookJob: Failed to download media content from Meta for ID: " . $mediaId, [
                                            'status' => $downloadResponse->status()
                                        ]);
                                    }
                                }
                            } else {
                                Log::warning("ProcessWebhookJob: Failed to get media download URL from Meta for ID: " . $mediaId, [
                                    'status' => $metaResponse->status()
                                ]);
                            }
                        } catch (Exception $e) {
                            Log::warning("ProcessWebhookJob: Exception during media download: " . $e->getMessage());
                        }
                    }
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
                $errorCode = $errors['code'] ?? null;
                $updateData['error_code'] = $errorCode;
                $updateData['error_message'] = data_get($errors, 'error_data.details')
                    ?? $errors['message']
                    ?? null;

                // Handle auto-opt out for block errors (131051 or 131026)
                if (in_array((int)$errorCode, [131051, 131026])) {
                    $conversation = $message->conversation;
                    if ($conversation) {
                        $contact = $conversation->contact;
                        if ($contact) {
                            \App\Models\OptOut::updateOrCreate([
                                'tenant_id' => $message->tenant_id,
                                'phone_number' => $contact->phone_number,
                            ], [
                                'opted_out_at' => now(),
                                'source' => 'blocked',
                            ]);
                            Log::info("Number {$contact->phone_number} automatically added to opt_outs due to block error {$errorCode}.");
                        }
                    }
                }
            }
        }

        if (!empty($updateData)) {
            $message->update($updateData);
            broadcast(new \App\Events\MessageBroadcasted($message));
        }

        // Update associated CampaignRecipient and Campaign stats if it exists
        $campaignRecipient = \App\Models\CampaignRecipient::where('whatsapp_msg_id', $msgId)->first();
        if ($campaignRecipient) {
            $recipientUpdate = [];
            
            // Only update recipient status if the new status is failed, or is further in progression
            $statusWeights = [
                'pending' => 0,
                'sending' => 1,
                'sent' => 2,
                'delivered' => 3,
                'read' => 4,
                'failed' => 5,
                'blocked' => 6,
            ];
            
            $currWeight = $statusWeights[$campaignRecipient->status] ?? 0;
            $newWeight = $statusWeights[$newStatus] ?? 0;
            
            $shouldUpdateRecipient = ($newStatus === 'failed' || $newWeight > $currWeight);
            
            if ($shouldUpdateRecipient) {
                $recipientUpdate['status'] = $newStatus;
                
                if ($newStatus === 'sent') {
                    $recipientUpdate['sent_at'] = $timestamp;
                } elseif ($newStatus === 'delivered') {
                    $recipientUpdate['delivered_at'] = $timestamp;
                } elseif ($newStatus === 'read') {
                    $recipientUpdate['read_at'] = $timestamp;
                } elseif ($newStatus === 'failed') {
                    $errors = $status['errors'][0] ?? null;
                    if ($errors) {
                        $errorCode = $errors['code'] ?? null;
                        $recipientUpdate['error_code'] = $errorCode;
                        $recipientUpdate['error_message'] = data_get($errors, 'error_data.details') ?? $errors['message'] ?? null;
                        if (in_array((int)$errorCode, [131051, 131026])) {
                            $recipientUpdate['status'] = 'blocked';
                        }
                    }
                }
                
                $campaignRecipient->update($recipientUpdate);
                
                // Recalculate campaign statistics
                $campaign = $campaignRecipient->campaign;
                if ($campaign) {
                    $campaign->update([
                        'sent_count' => $campaign->recipients()->whereIn('status', ['sent', 'delivered', 'read'])->count(),
                        'delivered_count' => $campaign->recipients()->whereIn('status', ['delivered', 'read'])->count(),
                        'read_count' => $campaign->recipients()->where('status', 'read')->count(),
                        'failed_count' => $campaign->recipients()->where('status', 'failed')->count(),
                        'blocked_count' => $campaign->recipients()->where('status', 'blocked')->count(),
                    ]);
                }
            }
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

    /**
     * Process WhatsApp Calling API events.
     */
    protected function processCallEvent(array $callData, WabaChannel $channel): void
    {
        $callId = $callData['id'] ?? null;
        $event = $callData['event'] ?? '';
        $session = $callData['session'] ?? null;

        if (!$callId) {
            return;
        }

        DB::transaction(function () use ($callId, $event, $session, $callData, $channel) {
            $call = \App\Models\Call::withoutGlobalScopes()
                ->where('whatsapp_call_id', $callId)
                ->first();

            if ($event === 'connect' && (!$call || ($callData['direction'] ?? '') === 'USER_INITIATED')) {
                // Inbound call
                $from = $callData['from'] ?? null;
                if (!$from) return;

                $fromNumber = '+' . ltrim($from, '+');
                $waId = $from;
                $timestamp = now();

                // Check if calling is disabled for this channel
                if (!$channel->calling_enabled) {
                    Log::info("Rejecting incoming call {$callId} for WABA channel {$channel->id} (calling is disabled).");

                    $apiVersion = config('services.meta.api_version', 'v23.0');
                    $url = "https://graph.facebook.com/{$apiVersion}/{$channel->phone_number_id}/calls";

                    Http::withHeaders([
                        'Authorization' => 'Bearer ' . $channel->decrypted_token,
                        'Content-Type' => 'application/json',
                    ])->post($url, [
                        'messaging_product' => 'whatsapp',
                        'call_id' => $callId,
                        'action' => 'reject',
                    ]);

                    // Still record a declined/missed call log for history
                    $call = \App\Models\Call::create([
                        'tenant_id' => $channel->tenant_id,
                        'conversation_id' => 0, // temporary
                        'direction' => 'inbound',
                        'whatsapp_call_id' => $callId,
                        'status' => 'missed',
                        'ended_at' => $timestamp,
                        'duration_seconds' => 0,
                    ]);

                    // Resolve Contact
                    $contact = Contact::withoutGlobalScopes()->where([
                        'tenant_id' => $channel->tenant_id,
                        'phone_number' => $fromNumber,
                    ])->first() ?: Contact::create([
                        'tenant_id' => $channel->tenant_id,
                        'phone_number' => $fromNumber,
                        'whatsapp_id' => $waId,
                        'name' => null,
                        'last_seen_at' => $timestamp,
                    ]);

                    // Resolve Conversation
                    $conversation = Conversation::withoutGlobalScopes()->where([
                        'tenant_id' => $channel->tenant_id,
                        'contact_id' => $contact->id,
                        'channel_id' => $channel->id,
                    ])->whereIn('status', ['open', 'resolved'])->first() ?: Conversation::withoutGlobalScopes()->create([
                        'tenant_id' => $channel->tenant_id,
                        'contact_id' => $contact->id,
                        'channel_id' => $channel->id,
                        'status' => 'open',
                        'window_expires_at' => $timestamp->copy()->addHours(24),
                        'last_message_at' => $timestamp,
                        'last_message_body' => '📞 Missed Call (Calling Disabled)',
                        'unread_count' => 1,
                    ]);

                    $call->update(['conversation_id' => $conversation->id]);

                    $message = Message::create([
                        'tenant_id' => $channel->tenant_id,
                        'conversation_id' => $conversation->id,
                        'call_id' => $call->id,
                        'direction' => 'inbound',
                        'type' => 'call',
                        'body' => '📞 Incoming Call: Missed (Calling Disabled)',
                        'status' => 'delivered',
                        'sent_at' => $timestamp,
                    ]);

                    broadcast(new \App\Events\MessageBroadcasted($message));
                    broadcast(new \App\Events\ConversationUpdated($conversation));
                    return;
                }

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
                        'name' => null,
                        'last_seen_at' => $timestamp,
                    ]);
                }

                // 2. Resolve Conversation
                $conversation = Conversation::withoutGlobalScopes()->where([
                    'tenant_id' => $channel->tenant_id,
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                ])->whereIn('status', ['open', 'resolved'])->first();

                if (!$conversation) {
                    $conversation = Conversation::withoutGlobalScopes()->create([
                        'tenant_id' => $channel->tenant_id,
                        'contact_id' => $contact->id,
                        'channel_id' => $channel->id,
                        'status' => 'open',
                        'window_expires_at' => $timestamp->copy()->addHours(24),
                        'last_message_at' => $timestamp,
                        'last_message_body' => '📞 Incoming Voice Call',
                        'unread_count' => 1,
                    ]);
                } else {
                    $conversation->update([
                        'status' => 'open',
                        'last_message_at' => $timestamp,
                        'last_message_body' => '📞 Incoming Voice Call',
                        'unread_count' => $conversation->unread_count + 1,
                    ]);
                }

                // 3. Create Call record
                $call = \App\Models\Call::create([
                    'tenant_id' => $channel->tenant_id,
                    'conversation_id' => $conversation->id,
                    'direction' => 'inbound',
                    'whatsapp_call_id' => $callId,
                    'status' => 'ringing',
                    'started_at' => null,
                ]);

                // 4. Create Message entry
                $message = Message::create([
                    'tenant_id' => $channel->tenant_id,
                    'conversation_id' => $conversation->id,
                    'call_id' => $call->id,
                    'direction' => 'inbound',
                    'type' => 'call',
                    'body' => 'Incoming Voice Call',
                    'status' => 'delivered',
                    'sent_at' => $timestamp,
                ]);

                // Broadcast WebRTC session with offer
                broadcast(new \App\Events\CallBroadcasted($call, $session));
                broadcast(new \App\Events\MessageBroadcasted($message));
                broadcast(new \App\Events\ConversationUpdated($conversation));
                return;
            }

            if (!$call) {
                return;
            }

            $conversation = $call->conversation;

            if ($event === 'connect') {
                $call->update([
                    'status' => 'connected',
                    'started_at' => $call->started_at ?? now(),
                ]);
                broadcast(new \App\Events\CallBroadcasted($call, $session));
            } elseif ($event === 'terminate') {
                $endedAt = now();
                $startedAt = $call->started_at;
                $duration = isset($callData['duration']) ? (int) $callData['duration'] : ($startedAt ? $endedAt->diffInSeconds($startedAt) : 0);
                
                // Determine billing rate based on country code
                $phone = $conversation->contact->phone_number;
                $cleanPhone = preg_replace('/\D/', '', $phone);
                $rates = config('calling_rates.default');
                
                foreach ([3, 2, 1] as $len) {
                    $prefix = substr($cleanPhone, 0, $len);
                    if (config("calling_rates.{$prefix}")) {
                        $rates = config("calling_rates.{$prefix}");
                        break;
                    }
                }

                $status = 'completed';
                if ($duration <= 0) {
                    $status = $call->direction === 'inbound' ? 'missed' : 'failed';
                }

                $call->update([
                    'status' => $status,
                    'ended_at' => $endedAt,
                    'duration_seconds' => $duration,
                    'rate_per_minute' => $rates['agent_rate'],
                    'cost' => 0,
                    'meta_cost' => 0,
                ]);

                // Calculate costs using pulse rules
                if ($call->direction === 'outbound' && $duration > 0) {
                    $pulses = ceil($duration / 6);
                    $call->cost = $pulses * ($rates['agent_rate'] / 10);
                    $call->meta_cost = $pulses * ($rates['meta_rate'] / 10);
                    $call->save();
                }

                // Format call display details
                $dirText = $call->direction === 'inbound' ? 'Incoming Call' : 'Outgoing Call';
                $statusText = $status === 'completed' ? 'Connected (' . gmdate("i:s", $duration) . ')' : ucfirst($status);
                $messageBody = "📞 {$dirText}: {$statusText}";

                // Create or update call log message
                $message = Message::withoutGlobalScopes()->where('call_id', $call->id)->first();
                if ($message) {
                    $message->update([
                        'body' => $messageBody,
                        'status' => 'delivered',
                    ]);
                } else {
                    $message = Message::create([
                        'tenant_id' => $call->tenant_id,
                        'conversation_id' => $call->conversation_id,
                        'call_id' => $call->id,
                        'direction' => $call->direction,
                        'type' => 'call',
                        'body' => $messageBody,
                        'status' => 'delivered',
                        'sent_at' => $endedAt,
                    ]);
                }

                // Update conversation preview text
                $conversation->update([
                    'last_message_body' => $messageBody,
                    'last_message_at' => $endedAt,
                ]);

                broadcast(new \App\Events\CallBroadcasted($call));
                broadcast(new \App\Events\MessageBroadcasted($message));
                broadcast(new \App\Events\ConversationUpdated($conversation));
            }
        });
    }
}
