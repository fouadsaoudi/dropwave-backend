<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Http\Controllers\Api\TemplateController;
use App\Services\MetaApiService;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Mockery;

class TemplateControllerValidateAiTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_validate_with_ai_delegates_to_gemini_service()
    {
        $metaMock = Mockery::mock(MetaApiService::class);
        $geminiMock = Mockery::mock(GeminiService::class);

        $expectedPayload = [
            'name' => 'order_shipped',
            'category' => 'UTILITY',
            'language' => 'en',
            'header_type' => 'text',
            'header_content' => 'Order Notice',
            'body' => 'Hello {{1}}, order {{2}} has been shipped!',
            'footer' => 'Thank you',
            'variable_examples' => ['1' => 'Alice', '2' => '9988']
        ];

        $geminiMock->shouldReceive('auditMessageTemplate')
            ->once()
            ->with(Mockery::on(function ($arg) use ($expectedPayload) {
                return $arg['name'] === $expectedPayload['name']
                    && $arg['category'] === $expectedPayload['category']
                    && $arg['body'] === $expectedPayload['body'];
            }))
            ->andReturn([
                'success' => true,
                'passed' => true,
                'status' => 'APPROVED',
                'confidence_score' => 99,
                'decision_summary' => 'Approved',
            ]);

        $controller = new TemplateController($metaMock, $geminiMock);

        $request = Request::create('/api/templates/validate-ai', 'POST', $expectedPayload);

        $response = $controller->validateWithAi($request);

        $this->assertEquals(200, $response->getStatusCode());
        $responseData = $response->getData(true);
        $this->assertTrue($responseData['success']);
        $this->assertTrue($responseData['passed']);
        $this->assertEquals('APPROVED', $responseData['status']);
    }
}
