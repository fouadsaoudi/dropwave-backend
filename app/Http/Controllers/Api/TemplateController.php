<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\WabaChannel;
use App\Http\Requests\StoreTemplateRequest;
use App\Services\MetaApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Exception;

class TemplateController extends Controller
{
    protected MetaApiService $metaService;

    public function __construct(MetaApiService $metaService)
    {
        $this->metaService = $metaService;
    }

    /**
     * Display a listing of local message templates.
     */
    public function index()
    {
        $templates = MessageTemplate::with('channel')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($templates);
    }

    /**
     * Store a newly created template and submit to Meta.
     */
    public function store(StoreTemplateRequest $request)
    {
        $channel = WabaChannel::find($request->channel_id);

        if (!$channel || $channel->tenant_id !== Auth::user()->tenant_id) {
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

        // 1. Build Meta API payload
        $components = [];

        // Body block (Required)
        $components[] = [
            'type' => 'BODY',
            'text' => $request->body,
        ];

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

            // Extract variables indexes from content body (e.g. {{1}}, {{2}})
            preg_match_all('/\{\{(\d+)\}\}/', $request->body, $matches);
            $variables = array_map('intval', array_unique($matches[1] ?? []));
            sort($variables);

            // 3. Save local template record in DB
            $template = MessageTemplate::create([
                'tenant_id' => Auth::user()->tenant_id,
                'channel_id' => $channel->id,
                'name' => $request->name,
                'category' => $request->category,
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
        // Get primary channel for tenant
        $channel = WabaChannel::where('tenant_id', Auth::user()->tenant_id)
            ->where('is_active', true)
            ->first();

        if (!$channel || !$channel->decrypted_token || !$channel->waba_id) {
            return response()->json([
                'error' => 'no_channel',
                'message' => 'No active WhatsApp channel found to sync templates.'
            ], 400);
        }

        try {
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
                
                // Map language locale code (e.g. en_US -> en)
                $lang = str_starts_with($metaTpl['language'], 'en') ? 'en' : $metaTpl['language'];

                $tplData = [
                    'meta_template_id' => $metaTpl['id'] ?? null,
                    'status' => $status,
                    'category' => $metaTpl['category'] ?? 'UTILITY',
                    'language' => $lang,
                    'header_type' => $headerType,
                    'header_content' => $headerContent,
                    'body' => $bodyText,
                    'footer' => $footerText,
                    'variables' => $variables,
                    'rejection_reason' => $metaTpl['rejection_reason'] ?? null,
                    'approved_at' => $status === 'APPROVED' ? now() : null,
                ];

                // Find local template by name
                $localTpl = MessageTemplate::withoutGlobalScopes()
                    ->where('tenant_id', Auth::user()->tenant_id)
                    ->where('name', $metaTpl['name'])
                    ->first();

                if ($localTpl) {
                    $localTpl->update($tplData);
                } else {
                    MessageTemplate::create(array_merge($tplData, [
                        'tenant_id' => Auth::user()->tenant_id,
                        'channel_id' => $channel->id,
                        'name' => $metaTpl['name'],
                    ]));
                }
                $syncedCount++;
            }

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
}
