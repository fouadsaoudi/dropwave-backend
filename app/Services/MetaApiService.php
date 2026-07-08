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
}
