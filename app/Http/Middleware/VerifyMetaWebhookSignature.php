<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use App\Models\MetaApp;

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

        $receivedHash = $parts[1];
        $content = $request->getContent();

        // 1. Check if route or query specifies a specific app_id
        $appId = $request->route('app_id') ?: $request->query('app_id');
        if ($appId) {
            $metaApp = MetaApp::findByAppId($appId);
            if ($metaApp && $metaApp->decrypted_app_secret) {
                $expected = hash_hmac('sha256', $content, $metaApp->decrypted_app_secret);
                if (hash_equals($expected, $receivedHash)) {
                    $request->attributes->set('meta_app', $metaApp);
                    return $next($request);
                }
            }
        }

        // 2. Multi-secret validation: test against all active MetaApps in database + config fallback
        $candidateSecrets = MetaApp::getCandidateSecrets();

        if (empty($candidateSecrets)) {
            Log::error('Meta App Secret is not configured in database or services.php.');
            return response()->json(['error' => 'Server configuration error'], 500);
        }

        foreach ($candidateSecrets as $secret) {
            $expected = hash_hmac('sha256', $content, $secret);
            if (hash_equals($expected, $receivedHash)) {
                return $next($request);
            }
        }

        Log::warning('Webhook signature mismatch across candidate secrets.', [
            'received' => $receivedHash,
            'candidates_count' => count($candidateSecrets),
            'app_id_param' => $appId,
        ]);

        return response()->json(['error' => 'Signature mismatch'], 403);
    }
}
