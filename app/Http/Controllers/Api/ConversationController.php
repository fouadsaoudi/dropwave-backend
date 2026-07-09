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
        $query = Conversation::with(['contact', 'channel', 'assignee']);

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            // Default: show open chats
            $query->whereIn('status', ['open']);
        }

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

        $conversations = $query->orderBy('last_message_at', 'desc')->get();

        return response()->json($conversations);
    }

    /**
     * Get counts of conversations scoped to active filter states.
     */
    public function counts(Request $request)
    {
        $active = Conversation::where('status', 'open')->whereNotNull('assigned_to')->count();
        $unassigned = Conversation::where('status', 'open')->whereNull('assigned_to')->count();
        $resolved = Conversation::where('status', 'resolved')->count();

        return response()->json([
            'active' => $active,
            'unassigned' => $unassigned,
            'resolved' => $resolved
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

        $messages = Message::where('conversation_id', $id)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages
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

        // 1. Enforce WhatsApp 24-hour service policy window
        if ($conversation->isWindowClosed()) {
            return response()->json([
                'error' => 'policy_violation',
                'message' => 'The WhatsApp 24-hour customer service window has expired. You can only send pre-approved template messages to this contact.'
            ], 422);
        }

        $channel = $conversation->channel;
        $contact = $conversation->contact;

        try {
            // 2. Dispatch message using Meta WhatsApp Business API
            $metaResponse = $this->metaService->sendTextMessage(
                $channel->decrypted_token,
                $channel->phone_number_id,
                $contact->phone_number,
                $request->body
            );

            $whatsappMsgId = $metaResponse['messages'][0]['id'] ?? null;

            // 3. Save message record to database
            $message = Message::create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'type' => 'text',
                'body' => $request->body,
                'whatsapp_msg_id' => $whatsappMsgId,
                'status' => 'sent',
                'sent_by' => Auth::id(),
                'sent_at' => now(),
            ]);

            // 4. Update conversation metadata
            $conversation->update([
                'last_message_body' => $request->body,
                'last_message_at' => now(),
            ]);

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
                'message' => 'Failed to transmit message to Meta WhatsApp Gateway.'
            ], 500);
        }
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

        $conversation->load(['contact', 'channel', 'assignee']);
        broadcast(new \App\Events\ConversationUpdated($conversation))->toOthers();

        return response()->json([
            'message' => 'Conversation marked as read.',
            'conversation' => $conversation
        ]);
    }
}
