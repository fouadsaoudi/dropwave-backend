<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Driver;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WabaChannel;
use App\Services\MetaApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class OrderController extends Controller
{
    protected MetaApiService $metaService;

    public function __construct(MetaApiService $metaService)
    {
        $this->metaService = $metaService;
    }

    public function store(Request $request, $conversationId)
    {
        $user = $request->user();

        // 1. Validate request payload
        $request->validate([
            'driver_id' => 'required|exists:drivers,id',
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|max:50',
            'delivery_address' => 'required|string|max:1000',
            'order_details' => 'required|string|max:2000',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'lang' => 'nullable|string|in:ar,en',
        ]);

        // 2. Fetch driver and verify workspace scoping
        $driver = Driver::where('id', $request->driver_id)->firstOrFail();

        // 3. Check if driver has an active 24h WhatsApp session window
        $contact = Contact::where('phone_number', $driver->phone_number)->first();
        $isOnline = false;
        if ($contact) {
            $conversation = Conversation::where('contact_id', $contact->id)->first();
            if ($conversation && $conversation->window_expires_at) {
                $isOnline = Carbon::parse($conversation->window_expires_at)->isFuture();
            }
        }

        if (!$isOnline) {
            return response()->json([
                'error' => 'policy_violation',
                'message' => "Driver's 24-hour session window is closed. Please ask the driver to send a message to become online first."
            ], 422);
        }

        // 4. Resolve the WABA channel from the customer's conversation
        $customerConversation = Conversation::find($conversationId);
        $channel = null;
        if ($customerConversation) {
            $channel = WabaChannel::where('tenant_id', $user->tenant_id)
                ->where('id', $customerConversation->channel_id)
                ->first();
        }

        // Fallback to the tenant's primary/first WABA channel
        if (!$channel) {
            $channel = WabaChannel::where('tenant_id', $user->tenant_id)->where('is_primary', true)->first()
                ?? WabaChannel::where('tenant_id', $user->tenant_id)->first();
        }

        if (!$channel || !$channel->decrypted_token) {
            return response()->json([
                'error' => 'channel_error',
                'message' => "No configured WABA channel found to send message to driver."
            ], 422);
        }

        // 5. Format order details message text
        $lat = $request->input('latitude');
        $lng = $request->input('longitude');

        try {
            return DB::transaction(function () use ($user, $driver, $conversationId, $request, $channel, $lat, $lng, $contact) {
                // a. Create Order record first to get auto-increment ID
                $order = Order::create([
                    'tenant_id' => $user->tenant_id,
                    'driver_id' => $driver->id,
                    'conversation_id' => $conversationId,
                    'customer_name' => $request->customer_name,
                    'customer_phone' => $request->customer_phone,
                    'delivery_address' => $request->delivery_address,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'order_details' => $request->order_details,
                    'status' => 'pending',
                ]);

                // b. Format order details message text with newly created order ID
                $formattedText = "📦 *New Delivery Order Details* (Order #" . $order->id . ")\n\n";
                $formattedText .= "*Customer:* " . $request->customer_name . " (" . $request->customer_phone . ")\n";
                $formattedText .= "*Address:* " . $request->delivery_address . "\n\n";
                $formattedText .= "*Items & Details:*\n" . $request->order_details . "\n";

                // Save full text to database log (with location link)
                $dbText = $formattedText;
                if ($lat && $lng) {
                    $dbText .= "\n📍 *Delivery Location:*\n";
                    $dbText .= "https://www.google.com/maps/search/?api=1&query=" . $lat . "," . $lng;
                }

                // c. Send the WhatsApp message to the driver (CTA interactive message if coords exist)
                if ($lat && $lng) {
                    $mapsUrl = "https://www.google.com/maps/search/?api=1&query=" . $lat . "," . $lng;
                    $lang = $request->input('lang', 'en');
                    $buttonText = ($lang === 'ar') ? "فتح الخريطة" : "Open Map";

                    $metaResponse = $this->metaService->sendCtaUrlMessage(
                        $channel->decrypted_token,
                        $channel->phone_number_id,
                        $driver->phone_number,
                        $formattedText,
                        $buttonText,
                        $mapsUrl
                    );
                } else {
                    $metaResponse = $this->metaService->sendTextMessage(
                        $channel->decrypted_token,
                        $channel->phone_number_id,
                        $driver->phone_number,
                        $formattedText
                    );
                }

                $whatsappMsgId = $metaResponse['messages'][0]['id'] ?? null;

                // c. Log outbound message in driver's conversation history
                // Resolve driver contact and conversation in case it was cleaned up
                $driverContact = $contact;
                if (!$driverContact) {
                    $driverContact = Contact::create([
                        'tenant_id' => $user->tenant_id,
                        'phone_number' => $driver->phone_number,
                        'whatsapp_id' => ltrim($driver->phone_number, '+'),
                        'name' => $driver->name,
                        'last_seen_at' => now(),
                    ]);
                }

                $driverConversation = Conversation::where('contact_id', $driverContact->id)->first();
                if (!$driverConversation) {
                    $driverConversation = Conversation::create([
                        'tenant_id' => $user->tenant_id,
                        'contact_id' => $driverContact->id,
                        'channel_id' => $channel->id,
                        'status' => 'open',
                        'window_expires_at' => now()->addHours(24),
                        'last_message_at' => now(),
                        'last_message_body' => substr($dbText, 0, 50),
                    ]);
                } else {
                    $driverConversation->update([
                        'channel_id' => $channel->id,
                    ]);
                }

                $message = Message::create([
                    'tenant_id' => $user->tenant_id,
                    'conversation_id' => $driverConversation->id,
                    'direction' => 'outbound',
                    'type' => 'text',
                    'body' => $dbText,
                    'whatsapp_msg_id' => $whatsappMsgId,
                    'status' => 'sent',
                    'sent_by' => $user->id,
                    'sent_at' => now(),
                ]);

                // Update driver conversation last message
                $driverConversation->update([
                    'last_message_body' => '📦 Order: ' . substr($request->order_details, 0, 30),
                    'last_message_at' => now(),
                ]);

                // Broadcast Reverb updates for driver's chat
                $message->load('sender');
                broadcast(new \App\Events\MessageBroadcasted($message));
                broadcast(new \App\Events\ConversationUpdated($driverConversation));

                return response()->json([
                    'message' => 'Order created and details sent to driver successfully.',
                    'order' => $order,
                    'chat_message' => $message,
                ]);
            });
        } catch (Exception $e) {
            Log::error("Failed to send order message to driver {$driver->id}", [
                'error' => $e->getMessage()
            ]);

            $message = $e->getMessage();
            if (str_contains($message, '131030') || str_contains($message, 'not in allowed list')) {
                $message .= " (Meta Sandbox restriction: You must add the driver's phone number to your WhatsApp Sandbox test numbers list on the Meta Developer Portal.)";
            }

            return response()->json([
                'error' => 'failed_send',
                'message' => 'Failed to transmit order details via WhatsApp: ' . $message
            ], 422);
        }
    }

    public function index(Request $request)
    {
        $orders = Order::with('driver')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($orders);
    }

    public function update(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        $request->validate([
            'status' => 'required|string|in:pending,delivered,cancelled',
        ]);

        $order->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'message' => 'Order status updated successfully.',
            'order' => $order->load('driver')
        ]);
    }
}
