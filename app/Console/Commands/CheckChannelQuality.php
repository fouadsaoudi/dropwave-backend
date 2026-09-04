<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WabaChannel;
use App\Mail\ChannelQualityWarningMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckChannelQuality extends Command
{
    protected $signature = 'channel:check-quality';
    protected $description = 'Check all active WhatsApp WABA channels and alert the admin via email if their quality rating is degraded (YELLOW or RED).';

    public function handle()
    {
        $this->info('Checking WhatsApp channels quality ratings...');

        // Fetch all active channels with YELLOW or RED quality ratings
        $degradedChannels = WabaChannel::withoutGlobalScopes()
            ->with('tenant')
            ->where('is_active', true)
            ->whereIn('quality_rating', ['YELLOW', 'RED'])
            ->get();

        if ($degradedChannels->isEmpty()) {
            $this->info('No degraded channels found. All channels are healthy (GREEN).');
            return 0;
        }

        $adminEmail = env('ADMIN_ALERT_EMAIL', 'fouad.saoudi94@gmail.com');
        $sentCount = 0;

        foreach ($degradedChannels as $channel) {
            $rating = $channel->quality_rating;
            
            // Generate a unique cache key for this channel alert to prevent spamming the admin every hour
            // The alert is cached for 24 hours so the admin receives at most one email per degraded channel per day.
            $cacheKey = "channel_quality_alert_{$channel->id}_{$rating}";

            if (!Cache::has($cacheKey)) {
                try {
                    Mail::to($adminEmail)->send(
                        new ChannelQualityWarningMail($channel, 'GREEN', $rating)
                    );

                    Cache::put($cacheKey, true, now()->addHours(24));
                    $sentCount++;

                    $this->warn("Alert email sent for Channel ID {$channel->id} ({$channel->display_name}) with status {$rating}.");
                } catch (\Exception $e) {
                    Log::error("Failed to send scheduled quality warning email for Channel ID {$channel->id}: " . $e->getMessage());
                    $this->error("Failed to send email for Channel ID {$channel->id}: " . $e->getMessage());
                }
            } else {
                $this->info("Skipped alert for Channel ID {$channel->id} ({$channel->display_name}) - alert recently sent in the last 24h.");
            }
        }

        $this->info("Quality check complete. Sent {$sentCount} alert email(s).");
        return 0;
    }
}
