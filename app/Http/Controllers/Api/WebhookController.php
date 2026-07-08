<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WebhookEvent;
use App\Jobs\ProcessWebhookJob;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Meta Webhook GET verification handshake.
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $verifyToken = config('services.meta.verify_token') ?: env('META_VERIFY_TOKEN');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            Log::info('Meta webhook verification handshake successful.');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('Meta webhook verification handshake failed.', [
            'mode' => $mode,
            'token_match' => $token === $verifyToken,
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Meta Webhook POST event receiver.
     */
    public function receive(Request $request)
    {
        $payload = $request->json()->all();

        Log::info('Meta webhook payload received.', ['payload_keys' => array_keys($payload)]);

        // 1. Audit log raw payload (guarantees we respond in < 5s)
        $event = WebhookEvent::create([
            'payload' => $payload,
            'processed' => false,
        ]);

        // 2. Dispatch job to parse in background (Redis queue)
        ProcessWebhookJob::dispatch($event->id);

        // 3. Return 200 OK to Meta immediately
        return response()->json(['status' => 'received']);
    }
}
