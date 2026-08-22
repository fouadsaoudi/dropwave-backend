<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\WabaChannel;
use App\Http\Requests\StoreTemplateRequest;
use App\Services\MetaApiService;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Exception;

class TemplateController extends Controller
{
    protected MetaApiService $metaService;
    protected GeminiService $geminiService;

    public function __construct(MetaApiService $metaService, GeminiService $geminiService)
    {
        $this->metaService = $metaService;
        $this->geminiService = $geminiService;
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user && $user->isAdmin()) {
            $templates = MessageTemplate::withoutGlobalScopes()
                ->with(['channel', 'tenant:id,name'])
                ->orderBy('created_at', 'desc')
                ->get();
        } else {
            $templates = MessageTemplate::with('channel')
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return response()->json($templates);
    }

    /**
     * Store a newly created template and submit to Meta.
     */
    public function store(StoreTemplateRequest $request)
    {
        $user = $request->user();
        $channel = WabaChannel::withoutGlobalScopes()->find($request->channel_id);

        if (!$channel || (!$user->isAdmin() && $channel->tenant_id !== $user->tenant_id)) {
            return response()->json([
                'error' => 'invalid_channel',
                'message' => 'Selected WhatsApp channel is invalid or not owned by this tenant.'
            ], 422);
        }

        // Normalize language code (Meta demands specific locales e.g. en_US)
        $langCode = $request->language;
        if ($langCode === 'en') {
            $langCode = 'en_US';
        }

        // Extract variables indexes from content body (e.g. {{1}}, {{2}})
        preg_match_all('/\{\{(\d+)\}\}/', $request->body, $matches);
        $variables = array_map('intval', array_unique($matches[1] ?? []));
        sort($variables);

        // 1. Build Meta API payload
        $components = [];

        $bodyComponent = [
            'type' => 'BODY',
            'text' => $request->body,
        ];

        // Attach variable examples if present in request (required by Meta Cloud API)
        if (!empty($variables) && $request->has('variable_examples')) {
            $bodySamples = [];
            foreach ($variables as $index) {
                $bodySamples[] = (string) ($request->input("variable_examples.{$index}") ?? "sample");
            }
            $bodyComponent['example'] = [
                'body_text' => [
                    $bodySamples
                ]
            ];
        }

        // Body block (Required)
        $components[] = $bodyComponent;

        // Header block (Optional)
        if ($request->header_type !== 'none') {
            $headerComponent = [
                'type' => 'HEADER',
                'format' => strtoupper($request->header_type),
            ];
            if ($request->header_type === 'text') {
                $headerComponent['text'] = $request->header_content;
            }
            $components[] = $headerComponent;
        }

        // Footer block (Optional)
        if (!empty($request->footer)) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $request->footer,
            ];
        }

        $metaPayload = [
            'name' => $request->name,
            'category' => $request->category,
            'language' => $langCode,
            'components' => $components,
        ];

        try {
            // 2. Submit template to Meta API
            $metaResponse = $this->metaService->submitMessageTemplate(
                $channel->decrypted_token,
                $channel->waba_id,
                $metaPayload
            );

            // 3. Save local template record in DB
            $template = MessageTemplate::create([
                'tenant_id' => $channel->tenant_id,
                'channel_id' => $channel->id,
                'name' => $request->name,
                'category' => $request->category,
                'billing_cost' => MessageTemplate::defaultBillingCostForCategory($request->category),
                'language' => $request->language,
                'status' => 'PENDING',
                'meta_template_id' => $metaResponse['id'] ?? null,
                'header_type' => $request->header_type,
                'header_content' => $request->header_content,
                'body' => $request->body,
                'footer' => $request->footer,
                'variables' => $variables,
                'submitted_at' => now(),
            ]);

            return response()->json([
                'message' => 'Template created and submitted to Meta successfully.',
                'template' => $template
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'submission_failed',
                'message' => 'Failed to submit template to Meta: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync local templates with status from Meta API.
     */
    public function sync(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $tenantId = 0;

            // Resolve tenant_id from template_id if provided
            $reqTemplateId = $request->input('template_id') 
                ?? $request->input('templateId') 
                ?? $request->query('template_id') 
                ?? $request->query('templateId') 
                ?? $request->json('template_id')
                ?? $request->json('templateId');

            if ($reqTemplateId) {
                $template = MessageTemplate::withoutGlobalScopes()->find($reqTemplateId);
                if ($template) {
                    $tenantId = (int) $template->tenant_id;
                }
            }

            // Fallback to explicit tenant_id parameters
            if ($tenantId === 0) {
                $tenantId = (int) ($request->input('tenant_id') 
                    ?? $request->input('tenantId') 
                    ?? $request->query('tenant_id') 
                    ?? $request->query('tenantId') 
                    ?? $request->json('tenant_id')
                    ?? $request->json('tenantId')
                    ?? 0);
            }

            // If no tenant is explicitly targeted, sync templates for ALL active tenants
            if ($tenantId === 0) {
                $channels = WabaChannel::withoutGlobalScopes()
                    ->where('is_active', true)
                    ->whereNotNull('access_token')
                    ->whereNotNull('waba_id')
                    ->with('tenant')
                    ->get();

                if ($channels->isEmpty()) {
                    return response()->json([
                        'error' => 'no_channels',
                        'message' => 'No active WhatsApp channels found on the system to sync.'
                    ], 400);
                }

                $syncedTenants = [];
                $totalSynced = 0;
                $errors = [];

                foreach ($channels as $channel) {
                    try {
                        $count = $this->syncChannelTemplates($channel);
                        $syncedTenants[] = [
                            'tenant_id' => $channel->tenant_id,
                            'tenant_name' => $channel->tenant?->name ?? 'Unknown',
                            'synced_count' => $count
                        ];
                        $totalSynced += $count;
                    } catch (Exception $e) {
                        $errors[] = [
                            'tenant_id' => $channel->tenant_id,
                            'tenant_name' => $channel->tenant?->name ?? 'Unknown',
                            'error' => $e->getMessage()
                        ];
                    }
                }

                return response()->json([
                    'message' => "Successfully synced templates across active tenant accounts.",
                    'total_synced_count' => $totalSynced,
                    'tenants_synced' => $syncedTenants,
                    'errors' => $errors
                ]);
            }
        } else {
            $tenantId = $user->tenant_id;
        }

        // Get primary channel for tenant
        $channel = WabaChannel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        if (!$channel || !$channel->decrypted_token || !$channel->waba_id) {
            return response()->json([
                'error' => 'no_channel',
                'message' => 'No active WhatsApp channel found to sync templates.'
            ], 400);
        }

        try {
            $syncedCount = $this->syncChannelTemplates($channel);

            return response()->json([
                'message' => "Successfully synced {$syncedCount} templates with Meta API.",
                'synced_count' => $syncedCount
            ]);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'sync_failed',
                'message' => 'Failed to sync templates with Meta: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Private helper to perform the actual templates sync for a single channel.
     */
    private function syncChannelTemplates(WabaChannel $channel): int
    {
        if (!$channel->decrypted_token || !$channel->waba_id) {
            return 0;
        }

        // Fetch latest templates schemas from Meta
        $metaData = $this->metaService->fetchMessageTemplates(
            $channel->decrypted_token,
            $channel->waba_id
        );

        $syncedCount = 0;

        foreach ($metaData['data'] ?? [] as $metaTpl) {
            // Parse component strings
            $bodyText = '';
            $headerType = 'none';
            $headerContent = null;
            $footerText = null;

            foreach ($metaTpl['components'] ?? [] as $comp) {
                if ($comp['type'] === 'BODY') {
                    $bodyText = $comp['text'] ?? '';
                } elseif ($comp['type'] === 'HEADER') {
                    $headerType = strtolower($comp['format'] ?? 'none');
                    $headerContent = $comp['text'] ?? null;
                } elseif ($comp['type'] === 'FOOTER') {
                    $footerText = $comp['text'] ?? null;
                }
            }

            preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $matches);
            $variables = array_map('intval', array_unique($matches[1] ?? []));
            sort($variables);

            $status = strtoupper($metaTpl['status'] ?? 'PENDING');
            
            // Keep the exact language code returned by Meta (e.g. en_US)
            $lang = $metaTpl['language'];

            $tplData = [
                'meta_template_id' => $metaTpl['id'] ?? null,
                'status' => $status,
                'category' => $metaTpl['category'] ?? 'UTILITY',
                'billing_cost' => MessageTemplate::defaultBillingCostForCategory($metaTpl['category'] ?? 'UTILITY'),
                'language' => $lang,
                'header_type' => $headerType,
                'header_content' => $headerContent,
                'body' => $bodyText,
                'footer' => $footerText,
                'variables' => $variables,
                'rejection_reason' => $metaTpl['rejected_reason'] ?? null,
                'approved_at' => $status === 'APPROVED' ? now() : null,
            ];

            // Find local template by name
            $localTpl = MessageTemplate::withoutGlobalScopes()
                ->where('tenant_id', $channel->tenant_id)
                ->where('name', $metaTpl['name'])
                ->first();

            if ($localTpl) {
                $localTpl->update($tplData);
            } else {
                MessageTemplate::create(array_merge($tplData, [
                    'tenant_id' => $channel->tenant_id,
                    'channel_id' => $channel->id,
                    'name' => $metaTpl['name'],
                ]));
            }
            $syncedCount++;
        }

        return $syncedCount;
    }

    /**
     * Delete the specified message template.
     */
    public function destroy($id)
    {
        $template = MessageTemplate::find($id);

        if (!$template) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Template not found.'
            ], 404);
        }

        $channel = $template->channel;

        if ($channel && $channel->decrypted_token && $channel->waba_id) {
            try {
                // Delete from Meta API
                $this->metaService->deleteMessageTemplate(
                    $channel->decrypted_token,
                    $channel->waba_id,
                    $template->name
                );
            } catch (Exception $e) {
                // Log failure but continue deletion locally if it's already gone on Meta
            }
        }

        $template->delete();

        return response()->json([
            'message' => 'Template deleted successfully.'
        ]);
    }

    /**
     * Audit a message template using Google Gemini AI against Meta policies.
     */
    public function validateWithAi(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:512',
            'category' => 'required|string|in:UTILITY,MARKETING,AUTHENTICATION',
            'language' => 'required|string|max:10',
            'header_type' => 'nullable|string|in:none,text',
            'header_content' => 'nullable|string|max:100',
            'body' => 'required|string|max:1024',
            'footer' => 'nullable|string|max:100',
            'variable_examples' => 'nullable|array',
        ]);

        $auditResult = $this->geminiService->auditMessageTemplate($validated);

        return response()->json($auditResult);
    }
}
