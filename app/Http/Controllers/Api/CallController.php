<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Conversation;
use App\Models\Call;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CallController extends Controller
{
    /**
     * List calling history for a conversation.
     */
    public function index(Request $request, $id)
    {
        $conversation = Conversation::findOrFail($id);
        $calls = Call::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($calls);
    }

    /**
     * Agent initiates outbound call.
     */
    public function initiate(Request $request, $id)
    {
        $request->validate([
            'sdp' => 'required|string',
        ]);

        $conversation = Conversation::findOrFail($id);
        $channel = $conversation->channel;
        $contact = $conversation->contact;

        $apiVersion = config('services.meta.api_version', 'v23.0');
        $url = "https://graph.facebook.com/{$apiVersion}/{$channel->phone_number_id}/calls";

        // Recipient phone without leading '+'
        $recipientPhone = ltrim($contact->phone_number, '+');

        Log::info("Initiating outbound call to {$recipientPhone} via WABA channel {$channel->id}");

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $channel->decrypted_token,
            'Content-Type' => 'application/json',
        ])->post($url, [
            'messaging_product' => 'whatsapp',
            'to' => $recipientPhone,
            'action' => 'connect',
            'session' => [
                'sdp_type' => 'offer',
                'sdp' => $request->input('sdp'),
            ],
        ]);

        if (!$response->successful()) {
            Log::error("Failed to initiate WhatsApp call", [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Meta API calling connection failed: ' . ($response->json('error.message') ?? 'Unknown error'),
            ], 400);
        }

        $metaData = $response->json();
        $whatsappCallId = $metaData['call_id'] ?? 'wacid.local_' . uniqid();

        $call = DB::transaction(function () use ($conversation, $whatsappCallId) {
            // Create call model
            $call = Call::create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'whatsapp_call_id' => $whatsappCallId,
                'status' => 'ringing',
                'started_at' => null,
            ]);

            // Create Message entry
            Message::create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'call_id' => $call->id,
                'direction' => 'outbound',
                'type' => 'call',
                'body' => '📞 Outgoing Voice Call: Ringing',
                'status' => 'pending',
                'sent_at' => now(),
            ]);

            return $call;
        });

        return response()->json([
            'success' => true,
            'call' => $call,
            'meta_response' => $metaData,
        ]);
    }

    /**
     * Agent accepts inbound call.
     */
    public function accept(Request $request, $id)
    {
        $request->validate([
            'call_id' => 'required|string',
            'sdp' => 'required|string',
        ]);

        $conversation = Conversation::findOrFail($id);
        $channel = $conversation->channel;
        
        $call = Call::where('whatsapp_call_id', $request->input('call_id'))
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        $apiVersion = config('services.meta.api_version', 'v23.0');
        $url = "https://graph.facebook.com/{$apiVersion}/{$channel->phone_number_id}/calls";

        Log::info("Accepting WhatsApp call {$call->whatsapp_call_id}");

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $channel->decrypted_token,
            'Content-Type' => 'application/json',
        ])->post($url, [
            'messaging_product' => 'whatsapp',
            'call_id' => $call->whatsapp_call_id,
            'action' => 'accept',
            'session' => [
                'sdp_type' => 'answer',
                'sdp' => $request->input('sdp'),
            ],
        ]);

        if (!$response->successful()) {
            Log::error("Failed to accept WhatsApp call", [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Meta API accept call connection failed: ' . ($response->json('error.message') ?? 'Unknown error'),
            ], 400);
        }

        $call->update([
            'status' => 'connected',
            'started_at' => $call->started_at ?? now(),
        ]);

        // Update system call message
        $message = Message::where('call_id', $call->id)->first();
        if ($message) {
            $message->update([
                'body' => '📞 Incoming Voice Call: Connected',
            ]);
        }

        return response()->json([
            'success' => true,
            'call' => $call,
            'meta_response' => $response->json(),
        ]);
    }

    /**
     * Terminate call session.
     */
    public function terminate(Request $request, $id)
    {
        $request->validate([
            'call_id' => 'required|string',
        ]);

        $conversation = Conversation::findOrFail($id);
        $channel = $conversation->channel;

        $call = Call::where('whatsapp_call_id', $request->input('call_id'))
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        $apiVersion = config('services.meta.api_version', 'v23.0');
        $url = "https://graph.facebook.com/{$apiVersion}/{$channel->phone_number_id}/calls";

        Log::info("Terminating WhatsApp call {$call->whatsapp_call_id}");

        // If call is already completed, skip Meta request
        if (!in_array($call->status, ['completed', 'failed', 'missed'])) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $channel->decrypted_token,
                'Content-Type' => 'application/json',
            ])->post($url, [
                'messaging_product' => 'whatsapp',
                'call_id' => $call->whatsapp_call_id,
                'action' => 'terminate',
            ]);

            if (!$response->successful()) {
                Log::warning("Failed to explicitly terminate WhatsApp call on Meta server, proceeding local cleanup", [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
            }
        }

        $endedAt = now();
        $startedAt = $call->started_at;
        $duration = $startedAt ? $endedAt->diffInSeconds($startedAt) : 0;

        // Billing rates
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

        if ($call->direction === 'outbound' && $duration > 0) {
            $pulses = ceil($duration / 6);
            $call->cost = $pulses * ($rates['agent_rate'] / 10);
            $call->meta_cost = $pulses * ($rates['meta_rate'] / 10);
            $call->save();
        }

        $dirText = $call->direction === 'inbound' ? 'Incoming Call' : 'Outgoing Call';
        $statusText = $status === 'completed' ? 'Connected (' . gmdate("i:s", $duration) . ')' : ucfirst($status);
        $messageBody = "📞 {$dirText}: {$statusText}";

        $message = Message::where('call_id', $call->id)->first();
        if ($message) {
            $message->update([
                'body' => $messageBody,
                'status' => 'delivered',
            ]);
        }

        $conversation->update([
            'last_message_body' => $messageBody,
            'last_message_at' => $endedAt,
        ]);

        return response()->json([
            'success' => true,
            'call' => $call,
        ]);
    }

    /**
     * Reject incoming call.
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'call_id' => 'required|string',
        ]);

        $conversation = Conversation::findOrFail($id);
        $channel = $conversation->channel;

        $call = Call::where('whatsapp_call_id', $request->input('call_id'))
            ->where('conversation_id', $conversation->id)
            ->firstOrFail();

        $apiVersion = config('services.meta.api_version', 'v23.0');
        $url = "https://graph.facebook.com/{$apiVersion}/{$channel->phone_number_id}/calls";

        Log::info("Rejecting WhatsApp call {$call->whatsapp_call_id}");

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $channel->decrypted_token,
            'Content-Type' => 'application/json',
        ])->post($url, [
            'messaging_product' => 'whatsapp',
            'call_id' => $call->whatsapp_call_id,
            'action' => 'reject',
        ]);

        if (!$response->successful()) {
            Log::error("Failed to reject WhatsApp call", [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Meta API reject call connection failed: ' . ($response->json('error.message') ?? 'Unknown error'),
            ], 400);
        }

        $call->update([
            'status' => 'missed',
            'ended_at' => now(),
            'duration_seconds' => 0,
        ]);

        $message = Message::where('call_id', $call->id)->first();
        if ($message) {
            $message->update([
                'body' => '📞 Incoming Voice Call: Declined',
                'status' => 'delivered',
            ]);
        }

        return response()->json([
            'success' => true,
            'call' => $call,
        ]);
    }

    /**
     * Generate dynamic short-lived COTURN WebRTC credentials.
     */
    public function getCredentials(Request $request)
    {
        $turnHost = env('TURN_SERVER_HOST', 'turn.socials-hub.com');
        $turnPort = env('TURN_SERVER_PORT', 3478);
        $turnTlsPort = env('TURN_SERVER_TLS_PORT', 5349);
        $turnSecret = env('TURN_SERVER_SECRET');

        $iceServers = [
            [
                'urls' => [
                    "stun:{$turnHost}:{$turnPort}",
                    "stun:stun.l.google.com:19302",
                    "stun:stun1.l.google.com:19302"
                ]
            ]
        ];

        if (!empty($turnSecret)) {
            // Expire in 24 hours (86400 seconds)
            $expiry = time() + 86400;
            $user = $request->user();
            $username = "{$expiry}:" . ($user ? $user->id : 'agent');

            // Standard COTURN HMAC-SHA1 signature
            $password = base64_encode(hash_hmac('sha1', $username, $turnSecret, true));

            $iceServers[] = [
                'urls' => [
                    "turn:{$turnHost}:{$turnPort}?transport=udp",
                    "turn:{$turnHost}:{$turnPort}?transport=tcp",
                    "turns:{$turnHost}:{$turnTlsPort}?transport=tcp"
                ],
                'username' => $username,
                'credential' => $password
            ];
        }

        return response()->json([
            'iceServers' => $iceServers
        ]);
    }
}
