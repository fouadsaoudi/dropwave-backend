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

        $disk = config('filesystems.media_disk', 'public');

        // If it's a local/S3 storage URL, stream the file directly
        if (!str_starts_with($message->media_url, 'http://') && !str_starts_with($message->media_url, 'https://')) {
            $relativePath = str_replace(['public/', 'storage/'], '', $message->media_url);

            if (!\Illuminate\Support\Facades\Storage::disk($disk)->exists($relativePath)) {
                // If it's not on the active disk, check the public disk as a fallback (for backward compatibility)
                if ($disk !== 'public' && \Illuminate\Support\Facades\Storage::disk('public')->exists($relativePath)) {
                    $contentType = $message->media_mime_type ?: \Illuminate\Support\Facades\Storage::disk('public')->mimeType($relativePath) ?: 'application/octet-stream';
                    return \Illuminate\Support\Facades\Storage::disk('public')->response($relativePath, null, [
                        'Content-Type' => $contentType,
                        'Cache-Control' => 'max-age=86400, public'
                    ]);
                }
                
                // Fallback to private local storage path if it was saved directly in app/
                $path = storage_path('app/' . $relativePath);
                if (file_exists($path)) {
                    $contentType = $message->media_mime_type ?: mime_content_type($path) ?: 'application/octet-stream';
                    return response()->file($path, [
                        'Content-Type' => $contentType,
                        'Cache-Control' => 'max-age=86400, public'
                    ]);
                }

                abort(404, 'Media file not found');
            }

            $contentType = $message->media_mime_type ?: \Illuminate\Support\Facades\Storage::disk($disk)->mimeType($relativePath) ?: 'application/octet-stream';
            return \Illuminate\Support\Facades\Storage::disk($disk)->response($relativePath, null, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'max-age=86400, public'
            ]);
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
            
            // Cache downloaded media locally inside conversation folder and update database record
            try {
                $mediaId = $message->media_filename ?: uniqid();
                
                $extension = 'bin';
                if ($contentType) {
                    $mimeParts = explode('/', $contentType);
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
                if ($contentType === 'audio/ogg' || $contentType === 'audio/ogg; codecs=opus') {
                    $extension = 'ogg';
                }

                $fileName = $mediaId . '.' . $extension;
                $storedFolder = 'conversations/' . $conversation->id;
                $relativePath = $storedFolder . '/' . $fileName;

                \Illuminate\Support\Facades\Storage::disk($disk)->put($relativePath, $response->body());

                // Update the message so next time it is loaded directly from local storage
                $message->update([
                    'media_url' => 'storage/' . $relativePath
                ]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("MediaController: Failed to cache proxy media locally for message: " . $message->id . ", error: " . $e->getMessage());
            }
            
            return response($response->body(), 200)
                ->header('Content-Type', $contentType)
                ->header('Cache-Control', 'max-age=86400, public');
                
        } catch (Exception $e) {
            abort(500, 'Error proxying media request: ' . $e->getMessage());
        }
    }
}
