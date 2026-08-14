<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\CampaignRecipient;
use App\Models\OptOut;
use App\Services\MetaApiService;
use Illuminate\Support\Facades\Log;
use Exception;

class SendCampaignMessageJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected int $recipientId;

    public function __construct(int $recipientId)
    {
        $this->recipientId = $recipientId;
    }

    public function handle(MetaApiService $metaService): void
    {
        $recipient = CampaignRecipient::find($this->recipientId);
        if (!$recipient || !in_array($recipient->status, ['pending', 'failed'])) {
            return;
        }

        $campaign = $recipient->campaign;
        if (!$campaign) {
            return;
        }

        $channel = $campaign->channel;
        if (!$channel || !$channel->decrypted_token || !$channel->waba_id) {
            $recipient->update([
                'status' => 'failed',
                'error_message' => 'WhatsApp channel credentials are not configured or invalid.',
            ]);
            $this->updateCampaignStats($campaign);
            return;
        }

        // 1. Validate Opt-Out status
        $isOptedOut = OptOut::where('tenant_id', $campaign->tenant_id)
            ->where('phone_number', $recipient->phone_number)
            ->exists();

        if ($isOptedOut) {
            $recipient->update([
                'status' => 'blocked',
                'error_message' => 'Recipient has blocked or opted out of messages.',
            ]);
            $this->updateCampaignStats($campaign);
            return;
        }

        // 2. Set status to sending
        $recipient->update(['status' => 'sending']);

        // 3. Compile template variable parameters
        $components = [];
        $variables = $recipient->variables ?? [];
        if (!empty($variables)) {
            $params = [];
            foreach ($variables as $varValue) {
                $params[] = [
                    'type' => 'text',
                    'text' => (string) $varValue,
                ];
            }
            $components[] = [
                'type' => 'body',
                'parameters' => $params,
            ];
        }

        try {
            $template = $campaign->template;
            $langCode = $template->language;
            if ($langCode === 'en') {
                $langCode = 'en_US';
            }

            // 4. Send Message via Meta API
            $response = $metaService->sendTemplateMessage(
                $channel->decrypted_token,
                $channel->phone_number_id,
                $recipient->phone_number,
                $template->name,
                $langCode,
                $components
            );

            $msgId = $response['messages'][0]['id'] ?? null;

            if ($msgId) {
                $recipient->update([
                    'status' => 'sent',
                    'whatsapp_msg_id' => $msgId,
                    'sent_at' => now(),
                ]);
            } else {
                throw new Exception('Meta API did not return a valid message ID.');
            }

        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorCode = null;
            
            // Try to extract structured error codes from Meta response if available
            if (str_contains($errorMessage, '{')) {
                try {
                    $jsonStart = strpos($errorMessage, '{');
                    $errJson = json_decode(substr($errorMessage, $jsonStart), true);
                    $errorCode = $errJson['error']['code'] ?? null;
                    $errorMessage = $errJson['error']['message'] ?? $errorMessage;
                } catch (Exception $_) {}
            }

            $isBlockError = in_array((int)$errorCode, [131051, 131026]);

            if ($isBlockError) {
                // Auto-register opt-out record
                OptOut::updateOrCreate([
                    'tenant_id' => $campaign->tenant_id,
                    'phone_number' => $recipient->phone_number,
                ], [
                    'opted_out_at' => now(),
                    'source' => 'blocked',
                ]);
            }

            $recipient->update([
                'status' => $isBlockError ? 'blocked' : 'failed',
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);

            Log::error("Campaign {$campaign->id} failed to send message to {$recipient->phone_number}: {$errorMessage}");
        }

        $this->updateCampaignStats($campaign);
    }

    protected function updateCampaignStats($campaign): void
    {
        $campaign->update([
            'sent_count' => $campaign->recipients()->whereIn('status', ['sent', 'delivered', 'read'])->count(),
            'delivered_count' => $campaign->recipients()->whereIn('status', ['delivered', 'read'])->count(),
            'read_count' => $campaign->recipients()->where('status', 'read')->count(),
            'failed_count' => $campaign->recipients()->where('status', 'failed')->count(),
            'blocked_count' => $campaign->recipients()->where('status', 'blocked')->count(),
        ]);
        
        // If all recipients are processed (not pending or sending), mark campaign as completed
        $pendingOrSending = $campaign->recipients()->whereIn('status', ['pending', 'sending'])->count();
        if ($pendingOrSending === 0 && $campaign->status === 'sending') {
            $campaign->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
            Log::info("Campaign {$campaign->id} completed successfully.");
        }
    }
}
