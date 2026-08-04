<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MetaApiService;
use App\Http\Requests\ListConversationsRequest;
use App\Http\Requests\ClaimConversationRequest;
use App\Http\Requests\ResolveConversationRequest;
use App\Http\Requests\ReopenConversationRequest;
use App\Http\Requests\SendMessageRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;

class ConversationController extends Controller
{
    protected MetaApiService $metaService;

    public function __construct(MetaApiService $metaService)
    {
        $this->metaService = $metaService;
    }

    /**
     * List all conversations with filters.
     */
    public function index(ListConversationsRequest $request)
    {
        $query = Conversation::with(['contact', 'assignee', 'channel']);

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            // Default: show open chats
            $query->whereIn('status', ['open']);
        }

        $user = $request->user();
        if ($user->isAgent()) {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->id)
                  ->orWhereNull('assigned_to');
            });

            if ($request->has('unassigned') && ($request->unassigned === 'true' || $request->unassigned === true || $request->unassigned === 1 || $request->unassigned === '1')) {
                $query->whereNull('assigned_to');
            } elseif ($request->has('assigned') && ($request->assigned === 'true' || $request->assigned === true || $request->assigned === 1 || $request->assigned === '1')) {
                $query->where('assigned_to', $user->id);
            }
        } else {
            // Filter by assigned agent
            if ($request->has('assigned_to')) {
                $query->where('assigned_to', $request->assigned_to);
            }

            // Filter by unassigned (unclaimed) chats
            if ($request->has('unassigned') && ($request->unassigned === 'true' || $request->unassigned === true || $request->unassigned === 1 || $request->unassigned === '1')) {
                $query->whereNull('assigned_to');
            }

            // Filter by assigned chats
            if ($request->has('assigned') && ($request->assigned === 'true' || $request->assigned === true || $request->assigned === 1 || $request->assigned === '1')) {
                $query->whereNotNull('assigned_to');
            }
        }

        $conversations = $query->orderBy('last_message_at', 'desc')->get();

        return response()->json($conversations);
    }

    /**
     * Get counts of conversations scoped to active filter states.
     */
    public function counts(Request $request)
    {
        $user = $request->user();

        $activeQuery = Conversation::where('status', 'open')->whereNotNull('assigned_to');
        $unassignedQuery = Conversation::where('status', 'open')->whereNull('assigned_to');
        $resolvedQuery = Conversation::where('status', 'resolved');

        if ($user->isAgent()) {
            $activeQuery->where('assigned_to', $user->id);
            $unassignedQuery->whereNull('assigned_to');
            $resolvedQuery->where('assigned_to', $user->id);
        }

        return response()->json([
            'active' => $activeQuery->count(),
            'unassigned' => $unassignedQuery->count(),
            'resolved' => $resolvedQuery->count()
        ]);
    }

    /**
     * Retrieve messages for a specific conversation.
     */
    public function messages(Request $request, $id)
    {
        $conversation = Conversation::with(['contact', 'channel', 'assignee'])->find($id);

        if (!$conversation) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Conversation not found.'
            ], 404);
        }

        $user = $request->user();
        if ($user->isAgent() && $conversation->assigned_to !== null && $conversation->assigned_to !== $user->id) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Unauthorized access to this conversation.'
            ], 403);
        }

        $query = Message::with('sender')->where('conversation_id', $id);

        if ($request->has('before_id')) {
            $query->where('id', '<', $request->before_id);
        }

        $messages = $query->orderBy('id', 'desc')
            ->limit(15)
            ->get()
            ->reverse()
            ->values();

        $hasMore = false;
        if ($messages->isNotEmpty()) {
            $oldestId = $messages->first()->id;
            $hasMore = Message::where('conversation_id', $id)
                ->where('id', '<', $oldestId)
                ->exists();
        }

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages,
            'has_more' => $hasMore
        ]);
    }

    /**
     * Claim a conversation by the authenticated agent.
     */
    public function claim(ClaimConversationRequest $request, $id)
    {
        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Conversation not found.'
            ], 404);
        }

        $user = $request->user();
        if ($user->isAgent() && $conversation->assigned_to !== null && $conversation->assigned_to !== $user->id) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Unauthorized access to this conversation.'
            ], 403);
        }

        $conversation->update([
            'assigned_to' => Auth::id(),
            'assigned_at' => now(),
            'status' => 'open'
        ]);

        $conversation->load(['contact', 'channel', 'assignee']);
        broadcast(new \App\Events\ConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => 'Conversation claimed successfully.',
            'conversation' => $conversation
        ]);
    }

    /**
     * Resolve/close a conversation.
     */
    public function resolve(ResolveConversationRequest $request, $id)
    {
        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Conversation not found.'
            ], 404);
        }

        $user = $request->user();
        if ($user->isAgent() && $conversation->assigned_to !== $user->id) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Unauthorized access to this conversation.'
            ], 403);
        }

        $conversation->update([
            'status' => 'resolved',
            'resolved_by' => Auth::id(),
            'resolved_at' => now()
        ]);

        $conversation->load(['contact', 'channel', 'assignee']);
        broadcast(new \App\Events\ConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => 'Conversation resolved successfully.',
            'conversation' => $conversation
        ]);
    }

    /**
     * Reopen a resolved conversation.
     */
    public function reopen(ReopenConversationRequest $request, $id)
    {
        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Conversation not found.'
            ], 404);
        }

        $user = $request->user();
        if ($user->isAgent() && $conversation->assigned_to !== $user->id) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Unauthorized access to this conversation.'
            ], 403);
        }

        $conversation->update([
            'status' => 'open',
            'resolved_by' => null,
            'resolved_at' => null
        ]);

        $conversation->load(['contact', 'channel', 'assignee']);
        broadcast(new \App\Events\ConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => 'Conversation reopened successfully.',
            'conversation' => $conversation
        ]);
    }

    /**
     * Send an outbound message to the customer.
     */
    public function sendMessage(SendMessageRequest $request, $id)
    {
        $conversation = Conversation::with('channel', 'contact')->find($id);

        if (!$conversation) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Conversation not found.'
            ], 404);
        }

        $user = $request->user();
        $isInternal = filter_var($request->input('is_internal'), FILTER_VALIDATE_BOOLEAN);

        // Check permissions
        $isManagerOrAdmin = $user->isManager() || $user->isAdmin();
        if (!$isManagerOrAdmin) {
            if ($user->isAgent()) {
                if (!$isInternal) {
                    // External message: agents must own the conversation
                    if ($conversation->assigned_to !== $user->id) {
                        return response()->json([
                            'error' => 'forbidden',
                            'message' => 'You must claim this conversation before sending a message.'
                        ], 403);
                    }
                } else {
                    // Internal note: agents must own the conversation (or it must be unassigned)
                    if ($conversation->assigned_to !== null && $conversation->assigned_to !== $user->id) {
                        return response()->json([
                            'error' => 'forbidden',
                            'message' => 'Unauthorized to add internal notes to another agent\'s conversation.'
                        ], 403);
                    }
                }
            }
        }

        // 1. Enforce WhatsApp 24-hour service policy window (Only for external messages)
        if (!$isInternal && $conversation->isWindowClosed()) {
            return response()->json([
                'error' => 'policy_violation',
                'message' => 'The WhatsApp 24-hour customer service window has expired. You can only send pre-approved template messages to this contact.'
            ], 422);
        }

        $channel = $conversation->channel;
        $contact = $conversation->contact;

        $hasFile = $request->hasFile('file');
        $mediaPath = null;
        $mediaMimeType = null;
        $mediaFilename = null;
        $whatsappMsgId = null;
        $msgType = 'text';
        $isVoiceMessage = false;

        try {
            if ($hasFile) {
                $file = $request->file('file');
                $mediaMimeType = $file->getClientMimeType() ?: 'application/octet-stream';
                $mediaFilename = $file->getClientOriginalName();
                $isVoiceMessage = str_starts_with(strtolower($mediaFilename), 'voice_record');

                // Determine message media type
                if (str_starts_with($mediaMimeType, 'image/') && $mediaMimeType !== 'image/svg+xml') {
                    $msgType = 'image';
                } elseif (str_starts_with($mediaMimeType, 'video/')) {
                    $msgType = 'video';
                } elseif (str_starts_with($mediaMimeType, 'audio/')) {
                    $msgType = 'audio';
                } else {
                    $msgType = 'document';
                }
                
                // Map CSV mime types to text/plain for Meta API compatibility
                $metaUploadMimeType = in_array($mediaMimeType, ['text/csv', 'application/csv', 'text/x-csv'])
                    ? 'text/plain'
                    : $mediaMimeType;
                
                // Store local copy on server with correct file extension
                $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin';
                if ($mediaMimeType === 'audio/mp4' && $extension === 'mp4') {
                    $extension = 'm4a';
                }
                $storedPath = $file->storeAs('conversations/' . $conversation->id, uniqid() . '.' . $extension, 'public');
                $mediaPath = 'storage/' . $storedPath;
                
                // Absolute path to upload to Meta
                $absolutePath = \Illuminate\Support\Facades\Storage::disk('public')->path($storedPath);

                if (!$isInternal) {
                    // Transcode recorded/voice-note audio to OGG/Opus so WhatsApp displays it natively as a playable voice note waveform
                    if ($msgType === 'audio' && (str_contains($mediaFilename, 'voice_record') || in_array($extension, ['webm', 'mp4', 'm4a', 'ogg']))) {
                        $transcodedPath = 'conversations/' . $conversation->id . '/' . uniqid() . '.ogg';
                        $absoluteTranscodedPath = \Illuminate\Support\Facades\Storage::disk('public')->path($transcodedPath);

                        $result = \Illuminate\Support\Facades\Process::run([
                            'ffmpeg', '-y', '-i', $absolutePath,
                            '-map', '0:a:0',
                            '-vn',
                            '-af', 'aresample=async=1:first_pts=0',
                            '-c:a', 'libopus',
                            '-b:a', '64k',
                            '-ac', '1',
                            '-application', 'voip',
                            '-f', 'ogg',
                            $absoluteTranscodedPath
                        ]);

                        if ($result->successful() && file_exists($absoluteTranscodedPath)) {
                            @unlink($absolutePath); // Delete the original file
                            
                            $storedPath = $transcodedPath;
                            $mediaPath = 'storage/' . $storedPath;
                            $absolutePath = $absoluteTranscodedPath;
                            $mediaMimeType = 'audio/ogg';
                            $metaUploadMimeType = 'audio/ogg';
                            $mediaFilename = 'voice_record.ogg';
                        } else {
                            Log::error("FFmpeg transcoding failed", [
                                'exit_code' => $result->exitCode(),
                                'output' => $result->output(),
                                'error' => $result->errorOutput()
                            ]);
                        }
                    }
                    
                    // 2.a. Upload media to Meta
                    $uploadResponse = $this->metaService->uploadMedia(
                        $channel->decrypted_token,
                        $channel->phone_number_id,
                        $absolutePath,
                        $metaUploadMimeType
                    );

                    $metaMediaId = $uploadResponse['id'] ?? null;
                    if (!$metaMediaId) {
                        throw new \Exception('Meta upload did not return a valid media ID');
                    }
                    
                    // 2.b. Dispatch media message using Meta WhatsApp Business API based on type
                    if ($msgType === 'image') {
                        $metaResponse = $this->metaService->sendImageMessage(
                            $channel->decrypted_token,
                            $channel->phone_number_id,
                            $contact->phone_number,
                            $metaMediaId,
                            $request->body // caption
                        );
                    } elseif ($msgType === 'video') {
                        $metaResponse = $this->metaService->sendVideoMessage(
                            $channel->decrypted_token,
                            $channel->phone_number_id,
                            $contact->phone_number,
                            $metaMediaId,
                            $request->body // caption
                        );
                    } elseif ($msgType === 'audio') {
                        $metaResponse = $this->metaService->sendAudioMessage(
                            $channel->decrypted_token,
                            $channel->phone_number_id,
                            $contact->phone_number,
                            $metaMediaId,
                            $isVoiceMessage
                        );
                    } else {
                        $metaResponse = $this->metaService->sendDocumentMessage(
                            $channel->decrypted_token,
                            $channel->phone_number_id,
                            $contact->phone_number,
                            $metaMediaId,
                            $mediaFilename,
                            $request->body // caption
                        );
                    }
                    
                    $whatsappMsgId = $metaResponse['messages'][0]['id'] ?? null;
                }
            } else {
                if (!$isInternal) {
                    // 2. Dispatch standard text message using Meta WhatsApp Business API
                    $metaResponse = $this->metaService->sendTextMessage(
                        $channel->decrypted_token,
                        $channel->phone_number_id,
                        $contact->phone_number,
                        $request->body
                    );

                    $whatsappMsgId = $metaResponse['messages'][0]['id'] ?? null;
                }
            }

            // 3. Save message record to database
            $message = Message::create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'type' => $msgType,
                'body' => $request->body,
                'media_url' => $mediaPath,
                'media_mime_type' => $mediaMimeType,
                'media_filename' => $mediaFilename,
                'whatsapp_msg_id' => $whatsappMsgId,
                'is_internal' => $isInternal,
                'status' => 'sent',
                'sent_by' => Auth::id(),
                'sent_at' => now(),
            ]);

            // 4. Update conversation metadata
            $lastSnippet = match ($msgType) {
                'image' => '📷 Photo',
                'video' => '🎥 Video',
                'audio' => '🎵 Audio',
                'document' => '📄 ' . ($mediaFilename ?: 'File'),
                default => $request->body,
            };

            if ($isInternal) {
                $lastSnippet = '📝 Note: ' . $lastSnippet;
            }

            $conversation->update([
                'last_message_body' => $lastSnippet,
                'last_message_at' => now(),
            ]);

            // Load sender relationship to broadcast it
            $message->load('sender');

            // 5. Broadcast message & conversation updates
            broadcast(new \App\Events\MessageBroadcasted($message))->toOthers();
            broadcast(new \App\Events\ConversationUpdated($conversation))->toOthers();

            return response()->json($message);

        } catch (Exception $e) {
            Log::error("Failed to send outbound WhatsApp reply to conversation {$id}", [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'failed_send',
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Assign a conversation to a specific user (Manager/Admin only).
     */
    public function assign(Request $request, $id)
    {
        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Conversation not found.'
            ], 404);
        }

        $user = $request->user();
        if (!$user->isManager() && !$user->isAdmin()) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Only managers and admins can reassign conversations.'
            ], 403);
        }

        $request->validate([
            'assigned_to' => 'nullable|exists:users,id'
        ]);

        $assignedTo = $request->input('assigned_to');
        
        // If assigned_to is provided, ensure they belong to the same tenant
        if ($assignedTo) {
            $targetUser = \App\Models\User::find($assignedTo);
            if ($targetUser->tenant_id !== $user->tenant_id) {
                return response()->json([
                    'error' => 'forbidden',
                    'message' => 'Cannot assign conversation to a user from another tenant.'
                ], 403);
            }
        }

        $conversation->update([
            'assigned_to' => $assignedTo,
            'assigned_at' => $assignedTo ? now() : null,
        ]);

        $conversation->load(['contact', 'channel', 'assignee']);
        broadcast(new \App\Events\ConversationUpdated($conversation))->toOthers();

        // Create a system log message as an internal note
        $assigneeName = $conversation->assignee ? $conversation->assignee->name : 'Unassigned';
        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => "Conversation reassigned to {$assigneeName} by {$user->name}.",
            'is_internal' => true,
            'status' => 'sent',
            'sent_by' => $user->id,
            'sent_at' => now(),
        ]);

        $message->load('sender');
        broadcast(new \App\Events\MessageBroadcasted($message))->toOthers();

        return response()->json([
            'message' => 'Conversation assigned successfully.',
            'conversation' => $conversation
        ]);
    }

    /**
     * Reset the unread_count for the conversation to 0.
     */
    public function markAsRead(Request $request, $id)
    {
        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Conversation not found.'
            ], 404);
        }

        // 1. Reset unread_count locally
        $conversation->update(['unread_count' => 0]);

        // 2. Find the last inbound message that hasn't been read yet to send a Meta read receipt
        $lastInboundMessage = Message::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->whereNotNull('whatsapp_msg_id')
            ->where('status', '!=', 'read')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastInboundMessage) {
            $channel = $conversation->channel;
            if ($channel && $channel->decrypted_token) {
                try {
                    $this->metaService->markMessageAsRead(
                        $channel->decrypted_token,
                        $channel->phone_number_id,
                        $lastInboundMessage->whatsapp_msg_id
                    );
                } catch (Exception $e) {
                    Log::warning("Failed to transmit WhatsApp read receipt to Meta for message {$lastInboundMessage->whatsapp_msg_id}: " . $e->getMessage());
                }
            }
        }

        // 3. Update all inbound messages in the database for this conversation to 'read'
        Message::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->where('status', '!=', 'read')
            ->update(['status' => 'read']);

        // 4. Mark associated notifications as read
        \App\Models\Notification::where('conversation_id', $conversation->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $conversation->load(['contact', 'channel', 'assignee']);
        broadcast(new \App\Events\ConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => 'Conversation marked as read.',
            'conversation' => $conversation
        ]);
    }

    /**
     * Send a template message to start a new chat or respond in an existing one.
     */
    public function sendTemplate(Request $request)
    {
        $request->validate([
            'template_id' => 'required|exists:message_templates,id',
            'phone_number' => 'nullable|string|max:30',
            'conversation_id' => 'nullable|integer|exists:conversations,id',
            'variables' => 'nullable|array',
        ]);

        $tenantId = Auth::user()->tenant_id;
        $template = \App\Models\MessageTemplate::find($request->template_id);

        if ($template->tenant_id !== $tenantId) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Unauthorized template request.'
            ], 403);
        }

        $conversation = null;
        $contact = null;

        if ($request->has('conversation_id') && !empty($request->conversation_id)) {
            $conversation = \App\Models\Conversation::find($request->conversation_id);
            if ($conversation->tenant_id !== $tenantId) {
                return response()->json([
                    'error' => 'unauthorized',
                    'message' => 'Unauthorized conversation access.'
                ], 403);
            }
            $contact = $conversation->contact;
        } elseif ($request->has('phone_number') && !empty($request->phone_number)) {
            // Standardize phone number format
            $phone = preg_replace('/[^\d+]/', '', $request->phone_number);
            if (!str_starts_with($phone, '+') && preg_match('/^\d/', $phone)) {
                $phone = '+' . $phone;
            }

            // Find or create Contact
            $contact = \App\Models\Contact::withoutGlobalScopes()->where([
                'tenant_id' => $tenantId,
                'phone_number' => $phone,
            ])->first();

            if (!$contact) {
                $contact = \App\Models\Contact::create([
                    'tenant_id' => $tenantId,
                    'phone_number' => $phone,
                    'added_via' => 'manual',
                ]);
            }

            // Find or create Conversation
            $conversation = \App\Models\Conversation::withoutGlobalScopes()->where([
                'tenant_id' => $tenantId,
                'contact_id' => $contact->id,
                'channel_id' => $template->channel_id,
            ])->first();

            if (!$conversation) {
                $conversation = \App\Models\Conversation::create([
                    'tenant_id' => $tenantId,
                    'contact_id' => $contact->id,
                    'channel_id' => $template->channel_id,
                    'status' => 'open',
                    'assigned_to' => Auth::id(),
                    'assigned_at' => now(),
                    'unread_count' => 0,
                ]);
            }
        } else {
            return response()->json([
                'error' => 'missing_parameters',
                'message' => 'Either phone_number or conversation_id must be provided.'
            ], 422);
        }

        $channel = $template->channel;

        // Compile components parameters for Meta API
        $components = [];
        if (!empty($request->variables)) {
            $params = [];
            foreach ($request->variables as $varValue) {
                $params[] = [
                    'type' => 'text',
                    'text' => (string) $varValue,
                ];
            }
            $components[] = [
                'type' => 'body',
                'parameters' => $params,
            ];
        }

        try {
            $langCode = $template->language;
            
            // 1. Send template using Meta API
            $metaResponse = $this->metaService->sendTemplateMessage(
                $channel->decrypted_token,
                $channel->phone_number_id,
                $contact->phone_number,
                $template->name,
                $langCode,
                $components
            );

            $whatsappMsgId = $metaResponse['messages'][0]['id'] ?? null;

            // Substitute variables in the body text for local display readability
            $msgBody = $template->body;
            if (!empty($request->variables)) {
                foreach ($request->variables as $index => $value) {
                    $placeholder = '{{' . ($index + 1) . '}}';
                    $msgBody = str_replace($placeholder, $value, $msgBody);
                }
            }

            // 2. Save outbound message record in database
            $message = \App\Models\Message::create([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'type' => 'text',
                'body' => $msgBody,
                'template_id' => $template->id,
                'whatsapp_msg_id' => $whatsappMsgId,
                'status' => 'sent',
                'sent_by' => Auth::id(),
                'sent_at' => now(),
            ]);

            // 3. Update conversation timeline details
            $conversation->update([
                'status' => 'open', // Ensure it opens/reopens if closed
                'last_message_body' => $msgBody,
                'last_message_at' => now(),
            ]);

            // 4. Broadcast live socket events
            $conversation->load(['contact', 'channel', 'assignee']);
            broadcast(new \App\Events\MessageBroadcasted($message))->toOthers();
            broadcast(new \App\Events\ConversationUpdated($conversation))->toOthers();

            return response()->json([
                'message' => 'Template message sent successfully.',
                'conversation' => $conversation,
                'chat_message' => $message,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'send_failed',
                'message' => 'Failed to transmit template message: ' . $e->getMessage()
            ], 500);
        }
    }
}
