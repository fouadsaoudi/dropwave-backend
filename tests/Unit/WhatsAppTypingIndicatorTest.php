<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\MetaApiService;
use Illuminate\Support\Facades\Http;

class WhatsAppTypingIndicatorTest extends TestCase
{
    public function test_mark_message_as_read_sends_correct_payload_without_typing_indicator()
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
        ]);

        $service = new MetaApiService();
        $response = $service->markMessageAsRead('token-123', '123456789', 'wamid.HBgLMTY1');

        $this->assertEquals(['success' => true], $response);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/123456789/messages')
                && $request['messaging_product'] === 'whatsapp'
                && $request['status'] === 'read'
                && $request['message_id'] === 'wamid.HBgLMTY1'
                && !isset($request['typing_indicator']);
        });
    }

    public function test_mark_message_as_read_sends_typing_indicator_payload_when_enabled()
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
        ]);

        $service = new MetaApiService();
        $response = $service->markMessageAsRead('token-123', '123456789', 'wamid.HBgLMTY1', true);

        $this->assertEquals(['success' => true], $response);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/123456789/messages')
                && $request['messaging_product'] === 'whatsapp'
                && $request['status'] === 'read'
                && $request['message_id'] === 'wamid.HBgLMTY1'
                && isset($request['typing_indicator'])
                && $request['typing_indicator']['type'] === 'text';
        });
    }

    public function test_send_typing_indicator_convenience_method()
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
        ]);

        $service = new MetaApiService();
        $response = $service->sendTypingIndicator('token-abc', '987654321', 'wamid.XYZ987');

        $this->assertEquals(['success' => true], $response);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/987654321/messages')
                && $request['messaging_product'] === 'whatsapp'
                && $request['status'] === 'read'
                && $request['message_id'] === 'wamid.XYZ987'
                && $request['typing_indicator']['type'] === 'text';
        });
    }
}
