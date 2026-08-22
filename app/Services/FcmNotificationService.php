<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFcmToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

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
     * Core sender: sends FCM message via HTTP v1 API to an array of tokens and prunes invalid/unregistered ones.
     */
    public static function sendToTokens(array $tokens, string $title, string $body, array $data = []): int
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if (empty($tokens)) {
            return 0;
        }

        // Stringify all data values for FCM v1 compatibility
        $stringData = array_map(function ($val) {
            if (is_array($val) || is_object($val)) {
                return json_encode($val);
            }
            return (string)$val;
        }, $data);

        try {
            /** @var Messaging $messaging */
            $messaging = app('firebase.messaging');

            $message = CloudMessage::new()
                ->withNotification(Notification::create($title, $body))
                ->withData($stringData)
                ->withAndroidConfig(AndroidConfig::fromArray([
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'high_importance_channel',
                        'sound' => 'default',
                        'default_sound' => true,
                        'default_vibrate_timings' => true,
                    ],
                ]))
                ->withApnsConfig(ApnsConfig::fromArray([
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                            'sound' => 'default',
                            'badge' => 1,
                            'content-available' => 1,
                        ],
                    ],
                ]));

            $report = $messaging->sendMulticast($message, $tokens);
            $successCount = $report->successes()->count();

            // Collect invalid / unregistered / unknown tokens to prune
            $staleTokens = array_unique(array_merge(
                $report->invalidTokens(),
                $report->unknownTokens()
            ));

            if (!empty($staleTokens)) {
                try {
                    UserFcmToken::whereIn('fcm_token', $staleTokens)->delete();
                    Log::info('[FCM] Pruned ' . count($staleTokens) . ' stale/invalid tokens: ' . implode(', ', $staleTokens));
                } catch (\Throwable $e) {
                    Log::warning('[FCM] Failed to delete stale tokens: ' . $e->getMessage());
                }
            }

            Log::info("[FCM v1] Dispatched push notification '{$title}' to {$successCount}/" . count($tokens) . " device(s).");

            return $successCount;
        } catch (\Throwable $e) {
            Log::error('[FCM v1] Exception while sending push notification: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return 0;
        }
    }
}

