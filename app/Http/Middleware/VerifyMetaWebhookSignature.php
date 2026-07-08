<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class VerifyMetaWebhookSignature
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip signature check in local testing environments
        if (app()->environment('local')) {
            return $next($request);
        }

        $signature = $request->header('X-Hub-Signature-256');

        if (!$signature) {
            Log::warning('Webhook signature missing.');
            return response()->json(['error' => 'Signature missing'], 403);
        }

        $parts = explode('=', $signature);
        if (count($parts) !== 2 || $parts[0] !== 'sha256') {
            Log::warning('Webhook signature format invalid.', ['signature' => $signature]);
            return response()->json(['error' => 'Signature format invalid'], 403);
        }

        $appSecret = config('services.meta.app_secret');

        if (!$appSecret) {
            Log::error('Meta App Secret is not configured in services.php.');
            return response()->json(['error' => 'Server configuration error'], 500);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $appSecret);

        if (!hash_equals($expected, $parts[1])) {
            Log::warning('Webhook signature mismatch.', [
                'expected' => $expected,
                'received' => $parts[1]
            ]);
            return response()->json(['error' => 'Signature mismatch'], 403);
        }

        return $next($request);
    }
}
