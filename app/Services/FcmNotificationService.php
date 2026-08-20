<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFcmToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmNotificationService
{
    /**
     * Send push notification to all active devices of a specific user.
     */
    public static function sendToUser(User|int $user, string $title, string $body, array $data = []): int
    {
        $userId = $user instanceof User ? $user->id : $user;
        $tokens = UserFcmToken::where('user_id', $userId)->pluck('fcm_token')->toArray();

        if (empty($tokens)) {
            return 0;
        }

        return self::sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Send push notification to multiple users.
     */
    public static function sendToUsers(iterable $userIds, string $title, string $body, array $data = []): int
    {
        $tokens = UserFcmToken::whereIn('user_id', $userIds)->pluck('fcm_token')->toArray();

        if (empty($tokens)) {
            return 0;
        }

        return self::sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Send notification to all available agents/managers in a tenant.
     */
    public static function sendToTenantAgents(int $tenantId, string $title, string $body, array $data = []): int
    {
        $tokens = UserFcmToken::whereHas('user', function ($query) use ($tenantId) {
            $query->where('tenant_id', $tenantId)->where('is_active', true);
        })->pluck('fcm_token')->toArray();

        if (empty($tokens)) {
            return 0;
        }

        return self::sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Core sender: sends FCM message to an array of tokens and prunes invalid/unregistered ones.
     */
    public static function sendToTokens(array $tokens, string $title, string $body, array $data = []): int
    {
        $tokens = array_unique(array_filter($tokens));
        if (empty($tokens)) {
            return 0;
        }

        $serverKey = config('services.fcm.server_key') ?? env('FCM_SERVER_KEY');

        // Stringify all data values for FCM compatibility
        $stringData = array_map(function ($val) {
            if (is_array($val) || is_object($val)) {
                return json_encode($val);
            }
            return (string)$val;
        }, $data);

        $successCount = 0;

        if ($serverKey) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'key=' . $serverKey,
                    'Content-Type' => 'application/json',
                ])->post('https://fcm.googleapis.com/fcm/send', [
                    'registration_ids' => array_values($tokens),
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                    ],
                    'data' => $stringData,
                    'priority' => 'high',
                ]);

                if ($response->successful()) {
                    $result = $response->json();
                    $successCount = $result['success'] ?? 0;

                    // Prune stale / unregistered tokens
                    if (!empty($result['results'])) {
                        foreach ($result['results'] as $index => $res) {
                            if (isset($res['error']) && in_array($res['error'], ['NotRegistered', 'InvalidRegistration', 'MismatchSenderId'])) {
                                $staleToken = $tokens[$index] ?? null;
                                if ($staleToken) {
                                    UserFcmToken::where('fcm_token', $staleToken)->delete();
                                    Log::info("[FCM] Pruned stale token: {$staleToken}");
                                }
                            }
                        }
                    }
                } else {
                    Log::warning('[FCM] Send failed: ' . $response->body());
                }
            } catch (\Throwable $e) {
                Log::error('[FCM] Exception while sending push notification: ' . $e->getMessage());
            }
        } else {
            Log::debug("[FCM] Push prepared for " . count($tokens) . " devices: '{$title}' - '{$body}'. Set FCM_SERVER_KEY in .env to enable dispatch.");
        }

        return $successCount;
    }
}
