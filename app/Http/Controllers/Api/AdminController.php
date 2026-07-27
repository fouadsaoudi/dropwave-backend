<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RecalculateTenantExpensesJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\WabaChannel;
use App\Models\User;
use App\Services\TenantBillingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Requests\ListTenantsRequest;
use App\Http\Requests\ListTenantChannelsRequest;
use App\Http\Requests\Admin\RecalculateTenantExpensesRequest;

class AdminController extends Controller
{
    private function requireAdmin(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['error' => 'forbidden', 'message' => 'Unauthorized admin access.'], 403);
        }

        return null;
    }

    /**
     * Get admin platform overview metrics (Admin only).
     */
    public function getOverview(Request $request)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $tenantCount = Tenant::count();
        $channelQuery = WabaChannel::withoutGlobalScopes()
            ->with(['tenant:id,name,slug'])
            ->get([
                'id',
                'tenant_id',
                'display_name',
                'phone_number',
                'phone_number_id',
                'waba_id',
                'quality_rating',
                'messaging_limit',
                'is_active',
                'is_primary',
                'connected_at',
                'token_expires_at',
                'access_token',
            ]);

        $channels = $channelQuery->map(function (WabaChannel $channel) {
            $issues = $this->getChannelHealthIssues($channel);
            $severity = $this->getChannelHealthSeverity($issues);

            return [
                'id' => $channel->id,
                'tenant' => [
                    'id' => $channel->tenant?->id,
                    'name' => $channel->tenant?->name,
                    'slug' => $channel->tenant?->slug,
                ],
                'display_name' => $channel->display_name,
                'phone_number' => $channel->phone_number,
                'phone_number_id' => $channel->phone_number_id,
                'waba_id' => $channel->waba_id,
                'quality_rating' => $channel->quality_rating,
                'messaging_limit' => $channel->messaging_limit,
                'is_active' => $channel->is_active,
                'is_primary' => $channel->is_primary,
                'connected_at' => optional($channel->connected_at)->toDateTimeString(),
                'token_expires_at' => optional($channel->token_expires_at)->toDateTimeString(),
                'issues' => $issues,
                'health_status' => $severity,
                'meta_ready' => empty($issues) && !empty($channel->waba_id) && !empty($channel->phone_number_id) && !empty($channel->getRawOriginal('access_token')),
            ];
        });

        $problematicChannels = $channels
            ->filter(fn ($channel) => $channel['health_status'] !== 'healthy')
            ->values();

        $healthyChannelsCount = $channels->count() - $problematicChannels->count();

        // Calculate billing overview totals
        $billingService = resolve(\App\Services\TenantBillingService::class);
        $tenants = Tenant::all();
        $now = \Carbon\Carbon::now();
        
        $totalAgentBilling = 0;
        $totalMetaBilling = 0;
        
        foreach ($tenants as $tenant) {
            $summary = $billingService->getMonthlySnapshotSummary($tenant, $now);
            $totalAgentBilling += (float) ($summary['total_estimated_cost'] ?? 0);
            $totalMetaBilling += (float) ($summary['meta_total_estimated_cost'] ?? 0);
        }
        
        $totalProfit = $totalAgentBilling - $totalMetaBilling;

        return response()->json([
            'tenants_count' => $tenantCount,
            'channels_count' => $channels->count(),
            'healthy_channels_count' => $healthyChannelsCount,
            'problematic_channels_count' => $problematicChannels->count(),
            'contacts_count' => Contact::withoutGlobalScopes()->count(),
            'conversations_count' => Conversation::withoutGlobalScopes()->count(),
            'problematic_channels' => $problematicChannels,
            'current_month_agent_expenses' => number_format($totalAgentBilling, 4, '.', ''),
            'current_month_meta_expenses' => number_format($totalMetaBilling, 4, '.', ''),
            'current_month_profit' => number_format($totalProfit, 4, '.', ''),
        ]);
    }

    /**
     * List all tenants in the system (Admin only).
     */
    public function listTenants(ListTenantsRequest $request)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $billingService = resolve(\App\Services\TenantBillingService::class);
        $tenants = Tenant::withCount('channels')
            ->get(['id', 'name', 'slug', 'contact_name', 'email', 'phone', 'is_active', 'created_at']);

        $billingMonthStr = $request->input('billing_month');
        $month = $billingMonthStr 
            ? \Carbon\Carbon::parse($billingMonthStr)->startOfMonth() 
            : \Carbon\Carbon::now()->startOfMonth();

        foreach ($tenants as $tenant) {
            $summary = $billingService->getMonthlySnapshotSummary($tenant, $month);
            $tenant->current_billing = [
                'total_estimated_cost' => $summary['total_estimated_cost'] ?? '0.0000',
                'meta_total_estimated_cost' => $summary['meta_total_estimated_cost'] ?? '0.0000',
                'conversation_sessions_count' => $summary['conversation_sessions_count'] ?? 0,
                'billable_conversations_count' => $summary['billable_conversations_count'] ?? 0,
                'template_cost_total' => $summary['template_cost_total'] ?? '0.0000',
                'meta_template_cost_total' => $summary['meta_template_cost_total'] ?? '0.0000',
                'calculated_at' => $summary['calculated_at'] ?? null,
            ];
        }

        return response()->json($tenants);
    }

    /**
     * List channels for a specific tenant (Admin only).
     */
    public function listTenantChannels(ListTenantChannelsRequest $request, $tenantId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Tenant not found.'
            ], 404);
        }

        $channels = WabaChannel::where('tenant_id', $tenantId)->get();

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'channels' => $channels
        ]);
    }

    /**
     * Get tenant details with billing and message totals (Admin only).
     */
    public function getTenantDetails(Request $request, $tenantId, TenantBillingService $billingService)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $tenant = Tenant::withCount('channels')->find($tenantId);

        if (!$tenant) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Tenant not found.'
            ], 404);
        }

        $currentMonth = Carbon::now()->startOfMonth();
        $excludeFailedTemplateMessages = function ($query) {
            $query->where(function ($nested) {
                $nested->whereNull('template_id')
                    ->orWhereNull('error_message');
            });
        };

        $totalMessagesSent = Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'outbound')
            ->where('status', '!=', 'failed')
            ->where($excludeFailedTemplateMessages)
            ->count();

        $currentMonthMessagesSent = Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'outbound')
            ->where('status', '!=', 'failed')
            ->whereBetween('created_at', [$currentMonth->copy()->startOfMonth(), $currentMonth->copy()->endOfMonth()->endOfDay()])
            ->where($excludeFailedTemplateMessages)
            ->count();

        $billing = $billingService->getMonthlySnapshotSummary($tenant, Carbon::now());
        $channels = WabaChannel::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get([
                'id',
                'display_name',
                'phone_number',
                'phone_number_id',
                'waba_id',
                'quality_rating',
                'messaging_limit',
                'is_active',
                'is_primary',
                'connected_at',
                'token_expires_at',
                'created_at',
            ]);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'contact_name' => $tenant->contact_name,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'is_active' => $tenant->is_active,
                'channels_count' => $tenant->channels_count,
                'created_at' => $tenant->created_at,
            ],
            'total_messages_sent' => $totalMessagesSent,
            'current_month_messages_sent' => $currentMonthMessagesSent,
            'current_expenses' => $billing['total_estimated_cost'],
            'meta_expenses' => $billing['meta_total_estimated_cost'] ?? '0.0000',
            'channels' => $channels,
            'billing' => array_merge($billing, ['currency' => 'USD']),
        ]);
    }

    /**
     * Recalculate billing expenses for a tenant (Admin only).
     */
    public function recalculateTenantExpenses(RecalculateTenantExpensesRequest $request, $tenantId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Tenant not found.'
            ], 404);
        }

        RecalculateTenantExpensesJob::dispatch(
            $tenant->id,
            $request->validated()['billing_month'] ?? null
        );

        return response()->json([
            'message' => 'Tenant billing recalculation queued successfully.',
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'billing_month' => $request->validated()['billing_month'] ?? now()->format('Y-m'),
        ]);
    }

    /**
     * List all system users with their roles and tenants (Admin only).
     */
    public function listUsers()
    {
        if ($response = $this->requireAdmin(request())) {
            return $response;
        }

        $users = User::with(['tenant', 'role'])
            ->get(['id', 'tenant_id', 'role_id', 'name', 'email', 'is_active', 'created_at']);

        return response()->json($users);
    }

    /**
     * Store/Create a new Tenant workspace (Admin only).
     */
    public function storeTenant(Request $request)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:tenants,slug|max:255',
            'email' => 'required|email|unique:tenants,email|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

        $tenant = Tenant::create([
            'name' => $request->name,
            'slug' => $request->slug,
            'email' => $request->email,
            'phone' => $request->phone,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Tenant workspace created successfully.',
            'tenant' => $tenant
        ], 201);
    }

    /**
     * Update an existing Tenant workspace (Admin only).
     */
    public function updateTenant(Request $request, $tenantId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Tenant not found.'
            ], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:tenants,slug,' . $tenant->id,
            'contact_name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:tenants,email,' . $tenant->id,
            'phone' => 'nullable|string|max:50',
            'is_active' => 'required|boolean',
        ]);

        $tenant->update([
            'name' => $request->name,
            'slug' => $request->slug,
            'contact_name' => $request->contact_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'is_active' => $request->is_active,
        ]);

        return response()->json([
            'message' => 'Tenant workspace updated successfully.',
            'tenant' => $tenant
        ]);
    }

    /**
     * Store/Create a new user under a tenant (Admin only).
     */
    public function storeUser(Request $request)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email|max:255',
            'password' => 'required|string|min:6',
            'role_id' => 'required|exists:roles,id',
        ]);

        $user = User::create([
            'tenant_id' => $request->tenant_id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role_id' => $request->role_id,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'User account created successfully.',
            'user' => $user->load(['tenant', 'role'])
        ], 201);
    }

    /**
     * Update an existing user's details (Admin only).
     */
    public function updateUser(Request $request, $id)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'role_id' => 'sometimes|required|exists:roles,id',
            'is_active' => 'sometimes|required|boolean',
            'password' => 'nullable|string|min:6',
        ]);

        if ($request->has('name')) {
            $user->name = $request->name;
        }
        if ($request->has('role_id')) {
            $user->role_id = $request->role_id;
        }
        if ($request->has('is_active')) {
            $user->is_active = $request->is_active;
        }
        if ($request->filled('password')) {
            $user->password = bcrypt($request->password);
        }

        $user->save();

        return response()->json([
            'message' => 'User account updated successfully.',
            'user' => $user->load(['tenant', 'role'])
        ]);
    }

    /**
     * Delete/Remove a user profile (Admin only).
     */
    public function deleteUser($id)
    {
        if ($response = $this->requireAdmin(request())) {
            return $response;
        }

        if (auth()->id() == $id) {
            return response()->json([
                'message' => 'Deletions denied: you cannot remove your active admin session profile.'
            ], 400);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->delete();

        return response()->json(['message' => 'User account deleted successfully.']);
    }

    /**
     * Add a WABA channel manually for a tenant (Admin only).
     */
    public function storeTenantChannel(Request $request, $tenantId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Tenant not found.'
            ], 404);
        }

        $request->validate([
            'display_name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:50',
            'phone_number_id' => 'required|string|max:100',
            'waba_id' => 'required|string|max:100',
            'access_token' => 'required|string',
            'is_active' => 'required|boolean',
            'is_primary' => 'required|boolean',
        ]);

        $metaService = resolve(\App\Services\MetaApiService::class);
        $qualityRating = 'GREEN';
        $messagingLimit = 'TIER_250';

        try {
            $phoneNumbers = $metaService->getWabaPhoneNumbers($request->waba_id, $request->access_token);
            $foundPhone = collect($phoneNumbers)->first(function ($phone) use ($request) {
                return (string) $phone['id'] === (string) $request->phone_number_id;
            });

            if ($foundPhone) {
                $qualityRating = strtoupper($foundPhone['quality_rating'] ?? 'GREEN');
                $messagingLimit = $foundPhone['messaging_limit_tier'] ?? 'TIER_250';
            } else {
                return response()->json([
                    'error' => 'validation_failed',
                    'message' => 'The Phone Number ID was not found in the phone numbers list associated with the WABA on Meta.'
                ], 422);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'meta_api_failed',
                'message' => 'Failed to connect to Meta API: ' . $e->getMessage()
            ], 422);
        }

        if ($request->is_primary) {
            WabaChannel::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->update(['is_primary' => false]);
        }

        $channel = WabaChannel::create([
            'tenant_id' => $tenantId,
            'display_name' => $request->display_name,
            'phone_number' => $request->phone_number,
            'phone_number_id' => $request->phone_number_id,
            'waba_id' => $request->waba_id,
            'access_token' => $request->access_token,
            'is_active' => $request->is_active,
            'is_primary' => $request->is_primary,
            'messaging_limit' => $messagingLimit,
            'quality_rating' => $qualityRating,
            'connected_at' => now(),
        ]);

        return response()->json([
            'message' => 'WABA channel added successfully.',
            'channel' => $channel
        ], 201);
    }

    /**
     * Update an existing WABA channel details (Admin only).
     */
    public function updateChannel(Request $request, $channelId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $channel = WabaChannel::withoutGlobalScopes()->find($channelId);
        if (!$channel) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'WABA channel not found.'
            ], 404);
        }

        $request->validate([
            'display_name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:50',
            'phone_number_id' => 'required|string|max:100',
            'waba_id' => 'required|string|max:100',
            'access_token' => 'nullable|string',
            'is_active' => 'required|boolean',
            'is_primary' => 'required|boolean',
        ]);

        $tokenToUse = !empty($request->access_token) ? $request->access_token : $channel->decrypted_token;
        $metaService = resolve(\App\Services\MetaApiService::class);
        $qualityRating = $channel->quality_rating;
        $messagingLimit = $channel->messaging_limit;

        try {
            $phoneNumbers = $metaService->getWabaPhoneNumbers($request->waba_id, $tokenToUse);
            $foundPhone = collect($phoneNumbers)->first(function ($phone) use ($request) {
                return (string) $phone['id'] === (string) $request->phone_number_id;
            });

            if ($foundPhone) {
                $qualityRating = strtoupper($foundPhone['quality_rating'] ?? 'GREEN');
                $messagingLimit = $foundPhone['messaging_limit_tier'] ?? 'TIER_250';
            } else {
                return response()->json([
                    'error' => 'validation_failed',
                    'message' => 'The Phone Number ID was not found in the phone numbers list associated with the WABA on Meta.'
                ], 422);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'meta_api_failed',
                'message' => 'Failed to connect to Meta API: ' . $e->getMessage()
            ], 422);
        }

        if ($request->is_primary) {
            WabaChannel::withoutGlobalScopes()
                ->where('tenant_id', $channel->tenant_id)
                ->update(['is_primary' => false]);
        }

        $updateData = [
            'display_name' => $request->display_name,
            'phone_number' => $request->phone_number,
            'phone_number_id' => $request->phone_number_id,
            'waba_id' => $request->waba_id,
            'is_active' => $request->is_active,
            'is_primary' => $request->is_primary,
            'messaging_limit' => $messagingLimit,
            'quality_rating' => $qualityRating,
        ];

        if (!empty($request->access_token)) {
            $updateData['access_token'] = $request->access_token;
        }

        $channel->update($updateData);

        return response()->json([
            'message' => 'WABA channel updated successfully.',
            'channel' => $channel
        ]);
    }

    /**
     * Delete/Remove a WABA channel (Admin only).
     */
    public function deleteChannel($channelId)
    {
        if ($response = $this->requireAdmin(request())) {
            return $response;
        }

        $channel = WabaChannel::withoutGlobalScopes()->find($channelId);
        if (!$channel) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'WABA channel not found.'
            ], 404);
        }

        $channel->delete();

        return response()->json([
            'message' => 'WABA channel deleted successfully.'
        ]);
    }

    /**
     * Override WABA webhook subscription for a channel (Admin only).
     */
    public function overrideChannelWebhook(Request $request, $channelId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $request->validate([
            'callback_uri' => 'required|url',
            'verify_token' => 'required|string|max:255',
        ]);

        $channel = WabaChannel::withoutGlobalScopes()->find($channelId);
        if (!$channel) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'WABA channel not found.'
            ], 404);
        }

        try {
            $metaService = resolve(\App\Services\MetaApiService::class);
            $response = $metaService->overrideWabaWebhook(
                $channel->waba_id,
                $channel->decrypted_token,
                $request->callback_uri,
                $request->verify_token
            );

            return response()->json([
                'message' => 'WABA webhook callback overridden successfully on Meta API.',
                'meta_response' => $response,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'override_failed',
                'message' => 'Failed to override WABA webhook: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getChannelHealthIssues(WabaChannel $channel): array
    {
        $issues = [];

        if (!$channel->is_active) {
            $issues[] = 'Channel is inactive.';
        }

        if (empty($channel->waba_id)) {
            $issues[] = 'Missing Meta WABA ID.';
        }

        if (empty($channel->phone_number_id)) {
            $issues[] = 'Missing Meta phone number ID.';
        }

        if (empty($channel->access_token)) {
            $issues[] = 'Missing Meta access token.';
        }

        if ($channel->token_expires_at && Carbon::parse($channel->token_expires_at)->isPast()) {
            $issues[] = 'Meta access token is expired.';
        }

        $rating = strtoupper((string) $channel->quality_rating);
        if ($rating === 'YELLOW') {
            $issues[] = 'Channel quality rating is degraded.';
        } elseif ($rating === 'RED') {
            $issues[] = 'Channel quality rating is critical.';
        } elseif (empty($rating)) {
            $issues[] = 'No Meta quality rating available.';
        }

        return $issues;
    }

    private function getChannelHealthSeverity(array $issues): string
    {
        $hasCritical = collect($issues)->contains(fn ($issue) => str_contains($issue, 'expired') || str_contains($issue, 'Missing') || str_contains($issue, 'critical'));

        if ($hasCritical) {
            return 'critical';
        }

        if (!empty($issues)) {
            return 'warning';
        }

        return 'healthy';
    }
}
