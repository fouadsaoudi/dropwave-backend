<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class MetaApiService
{
    protected string $baseUrl;
    protected string $version;
    protected ?string $appId;
    protected ?string $appSecret;

    public function __construct()
    {
        $this->baseUrl = config('services.meta.base_url') ?? 'https://graph.facebook.com';
        $this->version = config('services.meta.api_version') ?? 'v23.0';
        $this->appId = config('services.meta.app_id');
        $this->appSecret = config('services.meta.app_secret');
    }

    /**
     * Exchange the short-lived OAuth authorization code for an access token.
     */
    public function exchangeCodeForToken(string $code, string $redirectUri): array
    {
        $url = "{$this->baseUrl}/{$this->version}/oauth/access_token";

        $response = Http::post($url, [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if ($response->failed()) {
            Log::error('Meta API Token Exchange Failed', [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Failed to exchange authorization code with Meta: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Fetch WhatsApp Business Accounts (WABAs) shared with this app.
     */
    public function getSharedWabaAccounts(string $accessToken): array
    {
        // Use client token or user access token
        $url = "{$this->baseUrl}/{$this->version}/me/whatsapp_business_accounts";

        $response = Http::withToken($accessToken)->get($url);

        if ($response->failed()) {
            Log::error('Failed to retrieve WABA accounts', ['response' => $response->json()]);
            throw new Exception('Failed to retrieve WhatsApp Business Accounts from Meta.');
        }

        return $response->json('data') ?? [];
    }

    /**
     * Fetch phone numbers for a specific WABA.
     */
    public function getWabaPhoneNumbers(string $wabaId, string $accessToken): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$wabaId}/phone_numbers";

        $response = Http::withToken($accessToken)->get($url);

        if ($response->failed()) {
            Log::error("Failed to retrieve phone numbers for WABA {$wabaId}", ['response' => $response->json()]);
            throw new Exception('Failed to retrieve phone numbers for WABA from Meta.');
        }

        return $response->json('data') ?? [];
    }

    /**
     * Download media from Meta media ID.
     */
    public function downloadMedia(string $accessToken, string $mediaId): string
    {
        $url = "{$this->baseUrl}/{$this->version}/{$mediaId}";

        // 1. Get the media URL
        $response = Http::withToken($accessToken)->get($url);

        if ($response->failed()) {
            throw new Exception('Failed to get media URL from Meta.');
        }

        $mediaUrl = $response->json('url');

        // 2. Fetch the file content
        $fileResponse = Http::withToken($accessToken)->get($mediaUrl);

        if ($fileResponse->failed()) {
            throw new Exception('Failed to download media file from Meta.');
        }

        return $fileResponse->body();
    }

    /**
     * Send a free-text message to a WhatsApp number.
     */
    public function sendTextMessage(string $accessToken, string $phoneNumberId, string $to, string $body): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$phoneNumberId}/messages";

        $response = Http::withToken($accessToken)->post($url, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $body,
            ]
        ]);

        if ($response->failed()) {
            Log::error("Failed to send WhatsApp text message via {$phoneNumberId} to {$to}", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API send text message failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Send a pre-approved template message to initiate a chat or reply outside the 24h window.
     */
    public function sendTemplateMessage(string $accessToken, string $phoneNumberId, string $to, string $templateName, string $language = 'en', array $components = []): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $language,
                ]
            ]
        ];

        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        $response = Http::withToken($accessToken)->post($url, $payload);

        if ($response->failed()) {
            Log::error("Failed to send WhatsApp template message {$templateName} via {$phoneNumberId} to {$to}", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API send template message failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Subscribe the Meta App to the WhatsApp Business Account events (webhooks).
     */
    public function subscribeAppToWaba(string $wabaId, string $accessToken): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$wabaId}/subscribed_apps";

        $response = Http::withToken($accessToken)->post($url);

        if ($response->failed()) {
            Log::error("Failed to subscribe app to WABA {$wabaId}", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API WABA subscription failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Override the webhook callback URI and verify token for WABA events.
     */
    public function overrideWabaWebhook(string $wabaId, string $accessToken, string $callbackUri, string $verifyToken): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$wabaId}/subscribed_apps";

        $response = Http::withToken($accessToken)->post($url, [
            'override_callback_uri' => $callbackUri,
            'verify_token' => $verifyToken,
        ]);

        if ($response->failed()) {
            Log::error("Failed to override WABA {$wabaId} webhook", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API WABA webhook override failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Mark a received message as read.
     */
    public function markMessageAsRead(string $accessToken, string $phoneNumberId, string $messageId): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$phoneNumberId}/messages";

        $response = Http::withToken($accessToken)->post($url, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);

        if ($response->failed()) {
            Log::error("Failed to mark WhatsApp message {$messageId} as read via {$phoneNumberId}", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API mark message as read failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Submit a new message template to Meta for approval.
     */
    public function submitMessageTemplate(string $accessToken, string $wabaId, array $payload): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$wabaId}/message_templates";

        $response = Http::withToken($accessToken)->post($url, $payload);

        if ($response->failed()) {
            Log::error("Failed to submit WhatsApp message template to WABA {$wabaId}", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API template submission failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Fetch templates for a given WABA account from Meta.
     */
    public function fetchMessageTemplates(string $accessToken, string $wabaId): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$wabaId}/message_templates";

        $response = Http::withToken($accessToken)->get($url, [
            'fields' => 'name,status,components,language,category,rejected_reason'
        ]);

        if ($response->failed()) {
            Log::error("Failed to fetch WhatsApp message templates for WABA {$wabaId}", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API fetch templates failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Delete a message template from Meta.
     */
    public function deleteMessageTemplate(string $accessToken, string $wabaId, string $templateName): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$wabaId}/message_templates";

        $response = Http::withToken($accessToken)->delete($url, [
            'name' => $templateName,
        ]);

        if ($response->failed()) {
            Log::error("Failed to delete WhatsApp message template {$templateName} from WABA {$wabaId}", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API template deletion failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Upload media file to Meta.
     */
    public function uploadMedia(string $accessToken, string $phoneNumberId, string $filePath, string $mimeType): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$phoneNumberId}/media";
        // Meta's multipart `type` field accepts a media type, not MIME
        // parameters. In particular, sending `audio/ogg; codecs=opus` causes
        // Meta to process the upload as application/octet-stream even when the
        // OGG file itself is a valid Opus stream.
        $uploadMimeType = trim(explode(';', $mimeType, 2)[0]);

        $response = Http::withToken($accessToken)
            ->attach('file', file_get_contents($filePath), basename($filePath), [
                'Content-Type' => $uploadMimeType
            ])
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'type' => $uploadMimeType
            ]);

        if ($response->failed()) {
            Log::error("Failed to upload media via {$phoneNumberId}", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API media upload failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Send an image message using a Meta media ID.
     */
    public function sendImageMessage(string $accessToken, string $phoneNumberId, string $to, string $mediaId, ?string $caption = null): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'image',
            'image' => [
                'id' => $mediaId
            ]
        ];

        if ($caption) {
            $payload['image']['caption'] = $caption;
        }

        $response = Http::withToken($accessToken)->post($url, $payload);

        if ($response->failed()) {
            Log::error("Failed to send WhatsApp image message via {$phoneNumberId} to {$to}", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API send image message failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Send a document message using a Meta media ID.
     */
    public function sendDocumentMessage(string $accessToken, string $phoneNumberId, string $to, string $mediaId, string $filename, ?string $caption = null): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'document',
            'document' => [
                'id' => $mediaId,
                'filename' => $filename,
            ]
        ];

        if ($caption) {
            $payload['document']['caption'] = $caption;
        }

        $response = Http::withToken($accessToken)->post($url, $payload);

        if ($response->failed()) {
            Log::error("Failed to send WhatsApp document message via {$phoneNumberId} to {$to}", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API send document message failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Send a video message using a Meta media ID.
     */
    public function sendVideoMessage(string $accessToken, string $phoneNumberId, string $to, string $mediaId, ?string $caption = null): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'video',
            'video' => [
                'id' => $mediaId
            ]
        ];

        if ($caption) {
            $payload['video']['caption'] = $caption;
        }

        $response = Http::withToken($accessToken)->post($url, $payload);

        if ($response->failed()) {
            Log::error("Failed to send WhatsApp video message via {$phoneNumberId} to {$to}", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API send video message failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Send an audio message using a Meta media ID.
     */
    public function sendAudioMessage(string $accessToken, string $phoneNumberId, string $to, string $mediaId, bool $isVoiceMessage = false): array
    {
        $url = "{$this->baseUrl}/{$this->version}/{$phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'audio',
            'audio' => [
                'id' => $mediaId
            ]
        ];

        // This turns an Ogg/Opus recording into a WhatsApp voice message rather
        // than a basic audio attachment. Basic audio intentionally omits this.
        if ($isVoiceMessage) {
            $payload['audio']['voice'] = true;
        }

        $response = Http::withToken($accessToken)->post($url, $payload);

        if ($response->failed()) {
            Log::error("Failed to send WhatsApp audio message via {$phoneNumberId} to {$to}", [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            throw new Exception('Meta API send audio message failed: ' . $response->body());
        }

        return $response->json();
    }
}
