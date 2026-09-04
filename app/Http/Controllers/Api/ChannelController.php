<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\MetaApiService;
use App\Services\WhatsAppErrorService;
use App\Models\WabaChannel;
use App\Models\MetaApp;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

use App\Http\Requests\ConnectChannelRequest;

class ChannelController extends Controller
{
    protected MetaApiService $metaService;

    public function __construct(MetaApiService $metaService)
    {
        $this->metaService = $metaService;
    }

    /**
     * Connect WABAs and phone numbers using OAuth code from Embedded Signup.
     */
    public function connect(ConnectChannelRequest $request)
    {

        $tenantId = $request->get('tenant_id'); // From tenant middleware

        try {
            // 1. Exchange OAuth code for a long-lived access token
            $tokenData = $this->metaService->exchangeCodeForToken(
                $request->code,
                $request->redirect_uri
            );

            $accessToken = $tokenData['access_token'];

            // 2. Fetch the WABAs connected during signup
            $wabas = $this->metaService->getSharedWabaAccounts($accessToken);

            if (empty($wabas)) {
                return response()->json([
                    'error' => 'no_waba_found',
                    'message' => 'No WhatsApp Business Accounts found in the signup flow.',
                ], 404);
            }

            $connectedChannels = [];

            // 3. Process WABAs and their phone numbers
            DB::transaction(function () use ($wabas, $accessToken, $tenantId, &$connectedChannels) {
                foreach ($wabas as $waba) {
                    $wabaId = $waba['id'];
                    $wabaName = $waba['name'] ?? 'WhatsApp Account';

                    // Auto-subscribe the Meta App to WABA webhook notifications
                    try {
                        $this->metaService->subscribeAppToWaba($wabaId, $accessToken);
                    } catch (Exception $e) {
                        Log::warning("Failed to auto-subscribe to WABA {$wabaId} webhooks: " . $e->getMessage());
                    }

                    // Fetch numbers for this WABA
                    $phoneNumbers = $this->metaService->getWabaPhoneNumbers($wabaId, $accessToken);

                    foreach ($phoneNumbers as $phone) {
                        $phoneId = $phone['id'];
                        $number = $phone['display_phone_number'];
                        $name = $phone['verified_name'] ?? $wabaName;

                        // Create or update the channel for the current tenant
                        $channel = WabaChannel::updateOrCreate(
                            [
                                'tenant_id' => $tenantId,
                                'phone_number_id' => $phoneId,
                            ],
                            [
                                'display_name' => $name,
                                'phone_number' => $number,
                                'waba_id' => $wabaId,
                                'access_token' => $accessToken, // Mutator encrypts this automatically
                                'quality_rating' => $phone['quality_rating'] ?? 'GREEN',
                                'is_active' => true,
                                'connected_at' => now(),
                            ]
                        );

                        $connectedChannels[] = [
                            'id' => $channel->id,
                            'display_name' => $channel->display_name,
                            'phone_number' => $channel->phone_number,
                            'quality_rating' => $channel->quality_rating,
                        ];
                    }
                }
            });

            return response()->json([
                'message' => 'Channels connected successfully.',
                'channels' => $connectedChannels,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'connection_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually connect WABA and phone number for a tenant.
     */
    public function connectManual(Request $request)
    {
        $request->validate([
            'display_name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:255',
            'phone_number_id' => 'required|string|max:255',
            'waba_id' => 'required|string|max:255',
            'access_token' => 'required|string',
            'meta_app_id' => 'nullable|string|max:100',
            'meta_app_secret' => 'nullable|string|max:255',
            'meta_app_name' => 'nullable|string|max:255',
        ]);

        $tenantId = $request->get('tenant_id');

        try {
            $metaAppRecordId = null;
            if ($request->filled('meta_app_id')) {
                $inputAppId = trim($request->meta_app_id);
                $appSecret = trim($request->meta_app_secret ?? '');

                $existingApp = is_numeric($inputAppId) ? MetaApp::find($inputAppId) : null;
                if (!$existingApp) {
                    $existingApp = MetaApp::findByAppId($inputAppId);
                }

                if ($existingApp) {
                    if (!empty($appSecret)) {
                        $existingApp->app_secret = $appSecret;
                        $existingApp->save();
                    }
                    $metaAppRecordId = $existingApp->id;
                } elseif (!empty($appSecret)) {
                    $newApp = MetaApp::create([
                        'tenant_id' => $tenantId,
                        'name' => $request->meta_app_name ?: ($request->display_name . ' Meta App'),
                        'app_id' => $inputAppId,
                        'app_secret' => $appSecret,
                        'verify_token' => config('services.meta.verify_token') ?: 'dropwave_local_secure_token',
                        'is_active' => true,
                    ]);
                    $metaAppRecordId = $newApp->id;
                }
            }

            // Auto-subscribe the Meta App to WABA webhook notifications
            try {
                $this->metaService->subscribeAppToWaba($request->waba_id, $request->access_token);
            } catch (Exception $e) {
                Log::warning("Failed to auto-subscribe to WABA {$request->waba_id} webhooks during manual setup: " . $e->getMessage());
            }

            $updateData = [
                'display_name' => $request->display_name,
                'phone_number' => $request->phone_number,
                'waba_id' => $request->waba_id,
                'access_token' => $request->access_token, // Mutator encrypts this automatically
                'quality_rating' => 'GREEN',
                'is_active' => true,
                'connected_at' => now(),
            ];

            if ($metaAppRecordId) {
                $updateData['meta_app_id'] = $metaAppRecordId;
            }

            // Create or update the channel for the current tenant
            $channel = WabaChannel::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'phone_number_id' => $request->phone_number_id,
                ],
                $updateData
            );

            return response()->json([
                'message' => 'Channel connected manually successfully.',
                'channel' => [
                    'id' => $channel->id,
                    'display_name' => $channel->display_name,
                    'phone_number' => $channel->phone_number,
                    'quality_rating' => $channel->quality_rating,
                    'meta_app_id' => $channel->meta_app_id,
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'manual_connection_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all active channels for the tenant.
     */
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $channels = WabaChannel::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get([
                'id',
                'display_name',
                'phone_number',
                'phone_number_id',
                'quality_rating',
                'messaging_limit',
                'is_primary',
                'calling_enabled',
                'typing_indicator_enabled',
                'connected_at',
            ]);

        return response()->json($channels);
    }

    /**
     * Get Meta Phone Number Settings (Calling, Video, SIP, Identity Change).
     */
    public function getSettings(Request $request, $id)
    {
        $tenantId = $request->get('tenant_id');
        $channel = WabaChannel::where('tenant_id', $tenantId)->findOrFail($id);

        try {
            $apiVersion = config('services.meta.api_version', 'v23.0');

            // Refresh live quality_rating and messaging_limit from Meta
            try {
                $phoneUrl = "https://graph.facebook.com/{$apiVersion}/{$channel->phone_number_id}?fields=quality_rating,messaging_limit_tier,verified_name";
                $phoneResp = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $channel->decrypted_token,
                ])->get($phoneUrl);

                if ($phoneResp->successful()) {
                    $phoneData = $phoneResp->json();
                    $updates = [];
                    if (!empty($phoneData['quality_rating'])) {
                        $updates['quality_rating'] = strtoupper($phoneData['quality_rating']);
                    }
                    if (!empty($phoneData['messaging_limit_tier'])) {
                        $updates['messaging_limit'] = $phoneData['messaging_limit_tier'];
                    }
                    if (!empty($updates)) {
                        $channel->update($updates);
                    }
                }
            } catch (\Exception $e) {
                Log::debug("Could not refresh phone details for channel {$channel->id}: " . $e->getMessage());
            }

            $url = "https://graph.facebook.com/{$apiVersion}/{$channel->phone_number_id}/settings";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $channel->decrypted_token,
            ])->get($url, [
                'include_sip_credentials' => 'true',
            ]);

            if ($response->successful()) {
                $metaData = $response->json();
                return response()->json([
                    'success' => true,
                    'channel_id' => $channel->id,
                    'calling_enabled' => $channel->calling_enabled,
                    'typing_indicator_enabled' => (bool)($channel->typing_indicator_enabled ?? true),
                    'messaging_limit' => $channel->messaging_limit,
                    'quality_rating' => $channel->quality_rating,
                    'settings' => $metaData,
                ]);
            }

            Log::warning("Failed to fetch settings from Meta API for channel {$channel->id}", [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            // Fallback default structure
            return response()->json([
                'success' => false,
                'channel_id' => $channel->id,
                'calling_enabled' => $channel->calling_enabled,
                'typing_indicator_enabled' => (bool)($channel->typing_indicator_enabled ?? true),
                'messaging_limit' => $channel->messaging_limit,
                'quality_rating' => $channel->quality_rating,
                'message' => $response->json('error.message') ?? 'Could not retrieve settings from Meta.',
                'settings' => [
                    'calling' => [
                        'status' => $channel->calling_enabled ? 'enabled' : 'disabled',
                        'call_icon_visibility' => 'visible',
                        'video' => ['status' => 'disabled'],
                        'sip' => ['status' => 'disabled', 'servers' => []],
                        'srtp_key_exchange_protocol' => 'DTLS-SRTP',
                        'call_icons' => ['restrict_to_user_countries' => []],
                    ],
                    'user_identity_change' => [
                        'enabled' => false,
                    ]
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Exception retrieving settings for channel {$channel->id}: " . $e->getMessage());

            return response()->json([
                'error' => 'fetch_settings_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update Meta Phone Number Settings.
     */
    public function updateSettings(Request $request, $id)
    {
        $tenantId = $request->get('tenant_id');
        $channel = WabaChannel::where('tenant_id', $tenantId)->findOrFail($id);

        try {
            $apiVersion = config('services.meta.api_version', 'v23.0');
            $url = "https://graph.facebook.com/{$apiVersion}/{$channel->phone_number_id}/settings";
            $headers = [
                'Authorization' => 'Bearer ' . $channel->decrypted_token,
                'Content-Type' => 'application/json',
            ];

            $errors = [];

            // 1. Update Calling Settings (if provided)
            if ($request->has('calling')) {
                $callingInput = $request->input('calling');
                $callingPayload = [];

                if (isset($callingInput['status'])) {
                    $callingPayload['status'] = strtoupper($callingInput['status']) === 'ENABLED' ? 'ENABLED' : 'DISABLED';
                }

                if (isset($callingInput['call_icon_visibility'])) {
                    $vis = strtoupper($callingInput['call_icon_visibility']);
                    $callingPayload['call_icon_visibility'] = ($vis === 'HIDDEN' || $vis === 'DISABLE_ALL') ? 'DISABLE_ALL' : 'DEFAULT';
                }

                if (isset($callingInput['video']) && isset($callingInput['video']['status'])) {
                    $callingPayload['video'] = [
                        'status' => strtoupper($callingInput['video']['status']) === 'ENABLED' ? 'ENABLED' : 'DISABLED'
                    ];
                }

                if (isset($callingInput['sip']) && isset($callingInput['sip']['status'])) {
                    $callingPayload['sip'] = [
                        'status' => strtoupper($callingInput['sip']['status']) === 'ENABLED' ? 'ENABLED' : 'DISABLED'
                    ];
                }

                if (isset($callingInput['srtp_key_exchange_protocol'])) {
                    $proto = strtoupper($callingInput['srtp_key_exchange_protocol']);
                    if (str_contains($proto, 'DTLS')) {
                        $callingPayload['srtp_key_exchange_protocol'] = 'DTLS';
                    } elseif (str_contains($proto, 'SDES')) {
                        $callingPayload['srtp_key_exchange_protocol'] = 'SDES';
                    } else {
                        $callingPayload['srtp_key_exchange_protocol'] = 'NOT_SET';
                    }
                }

                if (isset($callingInput['call_icons']) && isset($callingInput['call_icons']['restrict_to_user_countries'])) {
                    $countries = $callingInput['call_icons']['restrict_to_user_countries'];
                    if (is_string($countries)) {
                        $countries = array_values(array_filter(array_map('trim', explode(',', $countries))));
                    }
                    $callingPayload['call_icons'] = [
                        'restrict_to_user_countries' => $countries
                    ];
                }

                if (isset($callingInput['call_hours'])) {
                    $callingPayload['call_hours'] = $callingInput['call_hours'];
                }

                if (!empty($callingPayload)) {
                    Log::info("Updating calling settings on Meta for channel {$channel->id}", $callingPayload);

                    $callResp = Http::withHeaders($headers)->post($url, [
                        'calling' => $callingPayload
                    ]);

                    if (!$callResp->successful()) {
                        Log::error("Failed to update calling settings on Meta", [
                            'status' => $callResp->status(),
                            'response' => $callResp->json(),
                        ]);
                        $parsed = WhatsAppErrorService::parse($callResp);
                        $errors[] = [
                            'field' => 'calling',
                            'title' => 'Calling Settings: ' . ($parsed['title'] ?? 'Error'),
                            'code' => $parsed['code'] ?? null,
                            'details' => $parsed['details'] ?? 'Failed to update calling settings.',
                            'reason' => $parsed['client_explanation'] ?? $parsed['possible_reasons'] ?? null,
                            'solution' => $parsed['client_solution'] ?? $parsed['possible_solutions'] ?? null,
                            'technical_reason' => $parsed['possible_reasons'] ?? null,
                            'technical_solution' => $parsed['possible_solutions'] ?? null,
                            'formatted_message' => $parsed['formatted_message'],
                        ];
                    } else {
                        // Sync local calling_enabled flag if status was updated
                        if (isset($callingPayload['status'])) {
                            $channel->update([
                                'calling_enabled' => ($callingPayload['status'] === 'ENABLED')
                            ]);
                        }
                    }
                }
            }

            // 2. Update User Identity Change Settings (if provided)
            if ($request->has('user_identity_change')) {
                $identityInput = $request->input('user_identity_change');
                $enabled = filter_var($identityInput['enable_identity_key_check'] ?? $identityInput['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

                Log::info("Updating user identity change settings on Meta for channel {$channel->id}", [
                    'enable_identity_key_check' => $enabled
                ]);

                $identityResp = Http::withHeaders($headers)->post($url, [
                    'user_identity_change' => [
                        'enable_identity_key_check' => $enabled
                    ]
                ]);

                if (!$identityResp->successful()) {
                    Log::error("Failed to update user identity change settings on Meta", [
                        'status' => $identityResp->status(),
                        'response' => $identityResp->json(),
                    ]);
                    $parsed = WhatsAppErrorService::parse($identityResp);
                    $errors[] = [
                        'field' => 'user_identity_change',
                        'title' => 'User Identity Change: ' . ($parsed['title'] ?? 'Error'),
                        'code' => $parsed['code'] ?? null,
                        'details' => $parsed['details'] ?? 'Failed to update user identity change settings.',
                        'reason' => $parsed['client_explanation'] ?? $parsed['possible_reasons'] ?? null,
                        'solution' => $parsed['client_solution'] ?? $parsed['possible_solutions'] ?? null,
                        'technical_reason' => $parsed['possible_reasons'] ?? null,
                        'technical_solution' => $parsed['possible_solutions'] ?? null,
                        'formatted_message' => $parsed['formatted_message'],
                    ];
                }
            }

            // 3. Update Typing Indicator Setting (local channel preference)
            if ($request->has('typing_indicator_enabled')) {
                $channel->update([
                    'typing_indicator_enabled' => $request->boolean('typing_indicator_enabled')
                ]);
            }

            if (!empty($errors)) {
                return response()->json([
                    'error' => 'partial_update_failed',
                    'message' => implode("\n\n", array_column($errors, 'formatted_message')),
                    'errors' => $errors,
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully.',
                'calling_enabled' => $channel->calling_enabled,
                'typing_indicator_enabled' => $channel->typing_indicator_enabled,
            ]);

        } catch (Exception $e) {
            Log::error("Exception updating settings for channel {$channel->id}: " . $e->getMessage());

            return response()->json([
                'error' => 'update_settings_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update local channel preferences (e.g. typing indicator) without touching Meta VoIP endpoints.
     */
    public function updatePreferences(Request $request, $id)
    {
        $tenantId = $request->get('tenant_id');
        $channel = WabaChannel::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'typing_indicator_enabled' => 'sometimes|boolean',
        ]);

        $channel->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Channel preferences updated successfully.',
            'channel' => [
                'id' => $channel->id,
                'typing_indicator_enabled' => $channel->typing_indicator_enabled,
            ],
        ]);
    }

    /**
     * Format a descriptive error message from Meta Graph API error response.
     */
    private function formatMetaErrorMessage($response): string
    {
        return WhatsAppErrorService::formatErrorMessage($response);
    }

    /**
     * Toggle calling status locally and on Meta API settings.
     */
    public function toggleCalling(Request $request, $id)
    {
        $tenantId = $request->get('tenant_id');
        $channel = WabaChannel::where('tenant_id', $tenantId)->findOrFail($id);

        $newStatus = !$channel->calling_enabled;
        $statusStr = $newStatus ? 'ENABLED' : 'DISABLED';

        try {
            $apiVersion = config('services.meta.api_version', 'v23.0');
            $url = "https://graph.facebook.com/{$apiVersion}/{$channel->phone_number_id}/settings";

            Log::info("Toggling WABA channel {$channel->id} calling setting on Meta to {$statusStr}");

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $channel->decrypted_token,
                'Content-Type' => 'application/json',
            ])->post($url, [
                'calling' => [
                    'status' => $statusStr,
                ]
            ]);

            if (!$response->successful()) {
                Log::error("Failed to update calling settings on Meta API", [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
                
                return response()->json([
                    'error' => 'meta_sync_failed',
                    'message' => 'Failed to synchronize setting with Meta API: ' . $this->formatMetaErrorMessage($response),
                ], 400);
            }

            $channel->update([
                'calling_enabled' => $newStatus
            ]);

            return response()->json([
                'success' => true,
                'message' => "Calling successfully " . ($newStatus ? 'enabled' : 'disabled') . ".",
                'calling_enabled' => $newStatus,
            ]);

        } catch (Exception $e) {
            Log::error("Exception toggling call status for channel {$channel->id}", [
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'toggle_failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
