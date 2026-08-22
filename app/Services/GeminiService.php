<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GeminiService
{
    protected ?string $apiKey;
    protected string $model;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->model = config('services.gemini.model', 'gemini-3.6-flash');
        $this->baseUrl = rtrim(config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
    }

    /**
     * Audit a WhatsApp message template using Google Gemini AI before Meta submission.
     *
     * @param array $templateData
     * @return array
     */
    public function auditMessageTemplate(array $templateData): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'api_key_missing' => true,
                'passed' => false,
                'status' => 'CONFIG_REQUIRED',
                'confidence_score' => 0,
                'decision_summary' => 'Google Gemini API key is not configured. Please add GEMINI_API_KEY in your backend/.env file to perform live AI audits.',
                'category_analysis' => [
                    'selected_category' => $templateData['category'] ?? 'UTILITY',
                    'recommended_category' => $templateData['category'] ?? 'UTILITY',
                    'is_category_correct' => true,
                    'explanation' => 'AI audit unavailable without API key.'
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
                    'Provide a valid GEMINI_API_KEY in backend/.env to unlock live Meta policy checks.'
                ],
                'suggested_template' => null
            ];
        }

        $systemPrompt = <<<PROMPT
You are a senior Meta WhatsApp Cloud API Template Reviewer and Compliance Auditor.
Your task is to strictly audit a proposed WhatsApp Message Template before it is submitted to Meta for review.

Meta rejects templates for the following reasons:
1. Category Mismatch:
   - UTILITY: Specific transactional updates, receipts, shipping alerts, order confirmations, billing notifications. It must NOT contain promotional phrases, discounts, coupons, upsells, sale announcements, marketing offers, or general engagement greetings.
   - MARKETING: Any promotional message, discount code, offer, seasonal campaign, product recommendation, or general marketing message.
   - AUTHENTICATION: One-time passwords (OTPs) and account verification codes with expiry instructions.
2. Variable Syntax and Layout:
   - Must use sequential numbers: {{1}}, {{2}}, {{3}} with no skipping (e.g., {{1}} then {{3}} is rejected).
   - Variables cannot be standalone at the start or end without surrounding context words.
   - Consecutive variables (e.g. {{1}}{{2}}) are strictly rejected.
   - Realistic sample/example values must be suitable.
3. Policy Violations:
   - WhatsApp Business Policy and Commerce Policy violations (weapons, drugs, adult, gambling, crypto scams, harassment, hate speech, counterfeit goods).
   - Excessive capitalization, aggressive punctuation (e.g., "BUY NOW!!!", "???"), or misleading links/shorteners.
4. Formatting Limits:
   - Header text max 60 characters.
   - Body text max 1024 characters.
   - Footer text max 60 characters.

Analyze the provided template and return a JSON object with this EXACT structure:
{
  "passed": boolean (true if template is 100% compliant with Meta guidelines and should be submitted, false otherwise),
  "status": string ("APPROVED" if passed, "NEEDS_REVISION" if minor/fixable issues or category mismatch, "REJECTED" if strict policy violations),
  "confidence_score": integer (0 to 100, representing probability of Meta approval),
  "decision_summary": string (concise explanation of the decision),
  "category_analysis": {
    "selected_category": string,
    "recommended_category": string,
    "is_category_correct": boolean,
    "explanation": string
  },
  "policy_compliance": {
    "violates_meta_policy": boolean,
    "violations": string[]
  },
  "formatting_compliance": {
    "is_valid_format": boolean,
    "issues": string[]
  },
  "recommendations": string[] (actionable, clear steps the user must take for guaranteed approval),
  "suggested_template": {
    "header": string or null,
    "body": string,
    "footer": string or null
  }
}
Respond ONLY with valid JSON.
PROMPT;

        $userContent = json_encode([
            'template_name' => $templateData['name'] ?? '',
            'category' => $templateData['category'] ?? 'UTILITY',
            'language' => $templateData['language'] ?? 'en',
            'header_type' => $templateData['header_type'] ?? 'none',
            'header_content' => $templateData['header_content'] ?? null,
            'body' => $templateData['body'] ?? '',
            'footer' => $templateData['footer'] ?? null,
            'variable_examples' => $templateData['variable_examples'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $endpoint = "{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}";

        try {
            $response = Http::timeout(25)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, [
                    'systemInstruction' => [
                        'parts' => [
                            ['text' => $systemPrompt]
                        ]
                    ],
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => "Audit this WhatsApp message template for Meta Cloud API approval:\n" . $userContent]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'temperature' => 0.1,
                    ]
                ]);

            if (!$response->successful()) {
                Log::error('Gemini API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'passed' => false,
                    'status' => 'ERROR',
                    'confidence_score' => 0,
                    'decision_summary' => 'Gemini AI service returned an error (' . $response->status() . '). ' . ($response->json('error.message') ?? 'Please check your API key or try again.'),
                    'category_analysis' => [
                        'selected_category' => $templateData['category'] ?? 'UTILITY',
                        'recommended_category' => $templateData['category'] ?? 'UTILITY',
                        'is_category_correct' => true,
                        'explanation' => 'Could not audit category due to API error.'
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
                        'Check that your Google Gemini API key has access to the model ' . $this->model,
                        'Try checking the template again.'
                    ],
                    'suggested_template' => null
                ];
            }

            $resultData = $response->json();
            $rawJsonText = $resultData['candidates'][0]['content']['parts'][0]['text'] ?? '';

            // Clean json text if wrapped in markdown code blocks
            $cleanJson = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($rawJsonText));
            $parsed = json_decode($cleanJson, true);

            if (!is_array($parsed) || !isset($parsed['passed'])) {
                Log::warning('Failed to parse Gemini AI response as JSON', ['raw' => $rawJsonText]);
                throw new Exception('Invalid JSON response format from Gemini AI.');
            }

            $usage = $resultData['usageMetadata'] ?? [];
            $promptTokens = (int) ($usage['promptTokenCount'] ?? 0);
            $completionTokens = (int) ($usage['candidatesTokenCount'] ?? 0);
            $totalTokens = (int) ($usage['totalTokenCount'] ?? ($promptTokens + $completionTokens));

            // Calculate estimated expense ($0.075 / 1M prompt tokens, $0.30 / 1M completion tokens)
            $estimatedCost = ($promptTokens * 0.000000075) + ($completionTokens * 0.00000030);

            $tokensSummary = [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_cost' => round($estimatedCost, 6),
            ];

            return array_merge(['success' => true, 'tokens' => $tokensSummary], $parsed);

        } catch (Exception $e) {
            Log::error('Exception in GeminiService@auditMessageTemplate', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'passed' => false,
                'status' => 'ERROR',
                'confidence_score' => 0,
                'decision_summary' => 'AI audit failed: ' . $e->getMessage(),
                'category_analysis' => [
                    'selected_category' => $templateData['category'] ?? 'UTILITY',
                    'recommended_category' => $templateData['category'] ?? 'UTILITY',
                    'is_category_correct' => true,
                    'explanation' => 'Audit service error.'
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
                    'Verify internet connection and Google Gemini API key configuration.',
                ],
                'suggested_template' => null
            ];
        }
    }
}
