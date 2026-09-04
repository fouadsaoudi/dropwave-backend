<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class GeminiServiceTest extends TestCase
{
    public function test_audit_returns_config_required_when_api_key_is_empty()
    {
        Config::set('services.gemini.api_key', null);

        $service = new GeminiService();
        $result = $service->auditMessageTemplate([
            'category' => 'UTILITY',
            'language' => 'en',
            'body' => 'Your code is {{1}}'
        ]);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['api_key_missing']);
        $this->assertFalse($result['passed']);
        $this->assertEquals('CONFIG_REQUIRED', $result['status']);
        $this->assertNotEmpty($result['recommendations']);
    }

    public function test_audit_passes_when_gemini_approves()
    {
        Config::set('services.gemini.api_key', 'test-key-123');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'passed' => true,
                                        'status' => 'APPROVED',
                                        'confidence_score' => 95,
                                        'decision_summary' => 'Compliant with Meta WhatsApp utility template policies.',
                                        'category_analysis' => [
                                            'selected_category' => 'UTILITY',
                                            'recommended_category' => 'UTILITY',
                                            'is_category_correct' => true,
                                            'explanation' => 'Accurate transactional notification.'
                                        ],
                                        'policy_compliance' => [
                                            'violates_meta_policy' => false,
                                            'violations' => []
                                        ],
                                        'formatting_compliance' => [
                                            'is_valid_format' => true,
                                            'issues' => []
                                        ],
                                        'recommendations' => ['Ready to submit.'],
                                        'suggested_template' => null
                                    ])
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $service = new GeminiService();
        $result = $service->auditMessageTemplate([
            'name' => 'order_shipped',
            'category' => 'UTILITY',
            'language' => 'en',
            'body' => 'Hello {{1}}, your order {{2}} has been shipped!',
            'variable_examples' => ['1' => 'Alice', '2' => '9988']
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['passed']);
        $this->assertEquals('APPROVED', $result['status']);
        $this->assertEquals(95, $result['confidence_score']);
    }

    public function test_audit_returns_recommendations_when_needs_revision()
    {
        Config::set('services.gemini.api_key', 'test-key-123');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'passed' => false,
                                        'status' => 'NEEDS_REVISION',
                                        'confidence_score' => 40,
                                        'decision_summary' => 'Promotional offer in a UTILITY template.',
                                        'category_analysis' => [
                                            'selected_category' => 'UTILITY',
                                            'recommended_category' => 'MARKETING',
                                            'is_category_correct' => false,
                                            'explanation' => 'Template has promotional discount.'
                                        ],
                                        'policy_compliance' => [
                                            'violates_meta_policy' => false,
                                            'violations' => []
                                        ],
                                        'formatting_compliance' => [
                                            'is_valid_format' => true,
                                            'issues' => []
                                        ],
                                        'recommendations' => [
                                            'Change category to MARKETING or remove discount code.'
                                        ],
                                        'suggested_template' => [
                                            'header' => null,
                                            'body' => 'Hello {{1}}, your order {{2}} is confirmed.',
                                            'footer' => null
                                        ]
                                    ])
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $service = new GeminiService();
        $result = $service->auditMessageTemplate([
            'name' => 'order_discount',
            'category' => 'UTILITY',
            'language' => 'en',
            'body' => 'Order {{1}} is confirmed! Get 20% discount with code SAVE20.'
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['passed']);
        $this->assertEquals('NEEDS_REVISION', $result['status']);
        $this->assertEquals(40, $result['confidence_score']);
        $this->assertCount(1, $result['recommendations']);
        $this->assertNotNull($result['suggested_template']);
    }
}
