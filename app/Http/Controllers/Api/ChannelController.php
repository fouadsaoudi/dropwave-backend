<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\MetaApiService;
use App\Models\WabaChannel;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * List all active channels for the tenant.
     */
    public function index(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $channels = WabaChannel::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get(['id', 'display_name', 'phone_number', 'phone_number_id', 'quality_rating', 'is_primary', 'connected_at']);

        return response()->json($channels);
    }
}
