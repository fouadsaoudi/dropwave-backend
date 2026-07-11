<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Exception;

class MediaController extends Controller
{
    public function proxy(Request $request)
    {
        $messageId = $request->query('message_id');
        
        if (!$messageId) {
            abort(400, 'Message ID is required');
        }

        // Retrieve the message
        // Note: Global tenant scope applies dynamically if user is logged in
        $message = Message::findOrFail($messageId);
        
        // 1. Authenticate the request via secure hash OR Sanctum auth
        $hash = $request->query('hash');
        $isAuthenticated = false;

        if ($hash) {
            $expectedHash = hash_hmac('sha256', $messageId, config('app.key'));
            if (hash_equals($expectedHash, $hash)) {
                $isAuthenticated = true;
            }
        } else {
            // Check Sanctum authentication
            $user = auth('sanctum')->user();
            if ($user) {
                // Double check tenant bounds if user has a tenant
                if (!$user->tenant_id || $message->tenant_id === $user->tenant_id) {
                    $isAuthenticated = true;
                }
            }
        }

        if (!$isAuthenticated) {
            abort(401, 'Unauthenticated or invalid media signature.');
        }

        if (!$message->media_url) {
            abort(404, 'Message has no media attachment');
        }
        
        // Get the WABA channel from the conversation
        $conversation = $message->conversation;
        if (!$conversation) {
            abort(404, 'Message conversation not found');
        }
        
        $channel = $conversation->channel;
        if (!$channel || !$channel->decrypted_token) {
            abort(403, 'WhatsApp channel authorization not found');
        }
        
        try {
            $mediaUrl = $message->media_url;

            // If we have a stored media ID (in media_filename), fetch the active URL from Meta Graph API
            if ($message->media_filename) {
                $apiVersion = config('services.meta.api_version', 'v20.0');
                $metaUrl = "https://graph.facebook.com/{$apiVersion}/{$message->media_filename}";
                
                $metaResponse = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $channel->decrypted_token
                ])->get($metaUrl);
                
                if ($metaResponse->successful()) {
                    $mediaUrl = $metaResponse->json('url') ?: $mediaUrl;
                } else {
                    \Illuminate\Support\Facades\Log::warning("Failed to fetch fresh media URL from Meta Graph for media ID: " . $message->media_filename, [
                        'status' => $metaResponse->status(),
                        'response' => $metaResponse->body()
                    ]);
                }
            }

            if (!$mediaUrl) {
                abort(404, 'Media URL not available');
            }

            // Fetch the media content using the decrypted token
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $channel->decrypted_token
            ])->get($mediaUrl);
            
            if (!$response->successful()) {
                // Return a clean 502 Bad Gateway instead of downstreaming Meta's 401/403
                // to avoid Laravel converting it into an Unauthenticated session error
                abort(502, 'Meta attachment server returned error code ' . $response->status());
            }
            
            $contentType = $message->media_mime_type ?: $response->header('Content-Type') ?: 'application/octet-stream';
            
            return response($response->body(), 200)
                ->header('Content-Type', $contentType)
                ->header('Cache-Control', 'max-age=86400, public');
                
        } catch (Exception $e) {
            abort(500, 'Error proxying media request: ' . $e->getMessage());
        }
    }
}
