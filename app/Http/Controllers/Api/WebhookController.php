<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WebhookEvent;
use App\Models\MetaApp;
use App\Jobs\ProcessWebhookJob;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Meta Webhook GET verification handshake.
     */
    public function verify(Request $request, ?string $appId = null)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $appId = $appId ?: $request->query('app_id');

        $envVerifyToken = config('services.meta.verify_token') ?: env('META_VERIFY_TOKEN');

        $isValid = false;

        if ($appId) {
            $metaApp = MetaApp::findByAppId($appId);
            if ($metaApp && ($metaApp->verify_token === $token || $envVerifyToken === $token)) {
                $isValid = true;
            }
        }

        if (!$isValid) {
            if ($token === $envVerifyToken) {
                $isValid = true;
            } elseif (MetaApp::where('verify_token', $token)->exists()) {
                $isValid = true;
            }
        }

        if ($mode === 'subscribe' && $isValid) {
            Log::info('Meta webhook verification handshake successful.', ['app_id' => $appId]);
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('Meta webhook verification handshake failed.', [
            'mode' => $mode,
            'token_match' => $isValid,
            'app_id' => $appId,
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Meta Webhook POST event receiver.
     */
    public function receive(Request $request, ?string $appId = null)
    {
        $payload = $request->json()->all();

        Log::info('Meta webhook payload received.', [
            'payload_keys' => array_keys($payload),
            'app_id' => $appId ?: $request->route('app_id')
        ]);

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
