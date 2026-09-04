<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WabaChannel;
use App\Services\MetaApiService;

class SendTestMessage extends Command
{
    protected $signature = 'message:send {phone : Recipient phone number in E.164 format (e.g. +96170123456)} {message : Message body or template variables (comma-separated)} {--template= : Send a Meta-approved template name instead of free text}';
    protected $description = 'Send a test WhatsApp message using connected WABA credentials';

    public function handle(MetaApiService $metaService)
    {
        // Fetch first active channel (Click & Pick is tenant 1)
        $channel = WabaChannel::withoutGlobalScopes()->where('is_active', true)->first();

        if (!$channel) {
            $this->error('No active WhatsApp channels found. Register one first using: php artisan channel:add');
            return 1;
        }

        $phone = $this->argument('phone');
        $message = $this->argument('message');
        $templateName = $this->option('template');

        $this->info("Channel: {$channel->display_name} ({$channel->phone_number})");
        $this->info("To: {$phone}");

        try {
            // Decrypt access token
            $token = decrypt($channel->access_token);

            if ($templateName) {
                $this->info("Type: Template Message ({$templateName})");
                
                $components = [];
                if ($message) {
                    $variables = explode(',', $message);
                    $parameters = [];
                    foreach ($variables as $var) {
                        $parameters[] = [
                            'type' => 'text',
                            'text' => trim($var)
                        ];
                    }
                    $components = [
                        [
                            'type' => 'body',
                            'parameters' => $parameters
                        ]
                    ];
                }

                $response = $metaService->sendTemplateMessage(
                    $token,
                    $channel->phone_number_id,
                    $phone,
                    $templateName,
                    'en', // default language
                    $components
                );
            } else {
                $this->info("Type: Free Text Message");
                $response = $metaService->sendTextMessage(
                    $token,
                    $channel->phone_number_id,
                    $phone,
                    $message
                );
            }

            $this->info("SUCCESS: Message sent successfully!");
            $this->line(json_encode($response, JSON_PRETTY_PRINT));
            return 0;

        } catch (\Exception $e) {
            $this->error("ERROR: Send request failed!");
            $this->error($e->getMessage());
            return 1;
        }
    }
}
