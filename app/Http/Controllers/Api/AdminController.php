<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RecalculateTenantExpensesJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Tenant;
use App\Models\WabaChannel;
use App\Models\TenantBillingSnapshot;
use App\Models\TenantAiUsage;
use App\Models\User;
use App\Models\Role;
use App\Services\TenantBillingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    private function requireAdminOrManager(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user || (!$user->isAdmin() && !$user->isManager())) {
            return response()->json(['error' => 'forbidden', 'message' => 'Unauthorized access.'], 403);
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

        // Calculate billing overview totals directly from tenant_billing_snapshots for high performance
        $now = Carbon::now();
        $billingMonthStr = $now->copy()->startOfMonth()->toDateString();
        
        $billingTotals = TenantBillingSnapshot::withoutGlobalScopes()
            ->whereDate('billing_month', $billingMonthStr)
            ->selectRaw("
                COALESCE(SUM(total_estimated_cost), 0) as total_agent_billing,
                COALESCE(SUM(meta_total_estimated_cost), 0) as total_meta_billing,
                COALESCE(SUM(meta_template_cost_total), 0) as total_real_meta_billing,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN amount_paid ELSE 0 END), 0) as total_paid_revenue,
                COALESCE(SUM(CASE WHEN payment_status != 'paid' OR payment_status IS NULL THEN total_estimated_cost ELSE 0 END), 0) as total_unpaid_revenue,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END), 0) as paid_tenants_count,
                COALESCE(SUM(CASE WHEN payment_status != 'paid' OR payment_status IS NULL THEN 1 ELSE 0 END), 0) as unpaid_tenants_count
            ")
            ->first();

        $totalAgentBilling = (float) ($billingTotals->total_agent_billing ?? 0);
        $totalMetaBilling = (float) ($billingTotals->total_meta_billing ?? 0);
        $totalRealMetaBilling = (float) ($billingTotals->total_real_meta_billing ?? 0);
        $totalPaidRevenue = (float) ($billingTotals->total_paid_revenue ?? 0);
        $totalUnpaidRevenue = (float) ($billingTotals->total_unpaid_revenue ?? 0);
        $paidTenantsCount = (int) ($billingTotals->paid_tenants_count ?? 0);
        $unpaidTenantsCount = (int) ($billingTotals->unpaid_tenants_count ?? 0);
        
        $totalProfit = $totalAgentBilling - $totalRealMetaBilling;

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
            'current_month_paid_revenue' => number_format($totalPaidRevenue, 4, '.', ''),
            'current_month_unpaid_revenue' => number_format($totalUnpaidRevenue, 4, '.', ''),
            'paid_tenants_count' => $paidTenantsCount,
            'unpaid_tenants_count' => $unpaidTenantsCount,
        ]);
    }

    /**
     * Update tenant billing payment status for a specific month (Admin only).
     */
    public function updateTenantPaymentStatus(Request $request, $tenantId, TenantBillingService $billingService)
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

        $validated = $request->validate([
            'payment_status' => 'required|in:unpaid,paid',
            'billing_month' => 'nullable|date_format:Y-m',
            'amount_paid' => 'nullable|numeric|min:0',
            'payment_notes' => 'nullable|string|max:1000',
        ]);

        $monthStr = $validated['billing_month'] ?? now()->format('Y-m');
        $month = \Carbon\Carbon::createFromFormat('Y-m', $monthStr)->startOfMonth();

        // Ensure snapshot exists
        $snapshot = $billingService->syncMonthlySnapshot($tenant, $month);

        $status = $validated['payment_status'];
        $amountPaid = isset($validated['amount_paid']) 
            ? (float) $validated['amount_paid'] 
            : ($status === 'paid' ? (float) $snapshot->total_estimated_cost : 0.0000);

        $snapshot->update([
            'payment_status' => $status,
            'paid_at' => $status === 'paid' ? ($snapshot->paid_at ?: now()) : null,
            'amount_paid' => $amountPaid,
            'payment_notes' => $validated['payment_notes'] ?? $snapshot->payment_notes,
        ]);

        $summary = $billingService->getMonthlySnapshotSummary($tenant, $month);

        return response()->json([
            'message' => 'Tenant payment status updated successfully.',
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'billing' => $summary,
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
        $query = Tenant::withCount('channels');

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->input('per_page', 15);
        $tenants = $query->orderBy('name', 'asc')->paginate($perPage);

        $billingMonthStr = $request->input('billing_month');
        $month = $billingMonthStr 
            ? \Carbon\Carbon::parse($billingMonthStr)->startOfMonth() 
            : \Carbon\Carbon::now()->startOfMonth();

        $tenants->getCollection()->transform(function ($tenant) use ($billingService, $month) {
            $summary = $billingService->getMonthlySnapshotSummary($tenant, $month);
            $tenant->current_billing = $summary;
            return $tenant;
        });

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
            'ai_usage' => TenantAiUsage::getTenantSummary($tenant->id),
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
     * List all system users with their roles and tenants (Admin or Manager).
     */
    public function listUsers()
    {
        $request = request();
        if ($response = $this->requireAdminOrManager($request)) {
            return $response;
        }

        $user = $request->user();
        $query = User::with(['tenant', 'role']);

        if ($user->isManager()) {
            $query->where('tenant_id', $user->tenant_id);
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->input('per_page', 15);
        $users = $query->orderBy('name', 'asc')->paginate($perPage);

        return response()->json($users);
    }

    /**
     * List all system roles (Admin or Manager).
     */
    public function listRoles()
    {
        $request = request();
        if ($response = $this->requireAdminOrManager($request)) {
            return $response;
        }

        $query = Role::query();
        if ($request->user()->isManager()) {
            $query->where('name', '!=', 'admin');
        }

        return response()->json($query->get(['id', 'name']));
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
            'contact_name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:tenants,email|max:255',
            'phone' => 'nullable|string|max:50',
            'type' => 'nullable|string|in:large_messaging_limit,delivery_coordination',
        ]);

        $tenant = Tenant::create([
            'name' => $request->name,
            'slug' => $request->slug,
            'contact_name' => $request->contact_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'type' => $request->type ?? 'large_messaging_limit',
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
            'type' => 'nullable|string|in:large_messaging_limit,delivery_coordination',
            'is_active' => 'required|boolean',
        ]);

        $tenant->update([
            'name' => $request->name,
            'slug' => $request->slug,
            'contact_name' => $request->contact_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'type' => $request->type ?? $tenant->type,
            'is_active' => $request->is_active,
        ]);

        return response()->json([
            'message' => 'Tenant workspace updated successfully.',
            'tenant' => $tenant
        ]);
    }

    /**
     * Store/Create a new user under a tenant (Admin or Manager).
     */
    public function storeUser(Request $request)
    {
        if ($response = $this->requireAdminOrManager($request)) {
            return $response;
        }

        $caller = $request->user();

        $request->validate([
            'tenant_id' => $caller->isManager() ? 'nullable' : 'nullable|exists:tenants,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email|max:255',
            'password' => 'required|string|min:6',
            'role_id' => 'required|exists:roles,id',
        ]);

        $role = Role::find($request->role_id);
        if ($caller->isManager() && $role && $role->name === 'admin') {
            return response()->json(['error' => 'validation', 'message' => 'Managers cannot assign the admin role.'], 422);
        }

        $tenantId = $caller->isManager() ? $caller->tenant_id : $request->tenant_id;

        $user = User::create([
            'tenant_id' => $tenantId,
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
     * Update an existing user's details (Admin or Manager).
     */
    public function updateUser(Request $request, $id)
    {
        if ($response = $this->requireAdminOrManager($request)) {
            return $response;
        }

        $caller = $request->user();

        $query = User::query();
        if ($caller->isManager()) {
            $query->where('tenant_id', $caller->tenant_id);
        }

        $user = $query->find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $request->validate([
            'tenant_id' => $caller->isManager() ? 'nullable' : 'nullable|exists:tenants,id',
            'name' => 'sometimes|required|string|max:255',
            'role_id' => 'sometimes|required|exists:roles,id',
            'is_active' => 'sometimes|required|boolean',
            'password' => 'nullable|string|min:6',
        ]);

        if ($request->has('role_id')) {
            $role = Role::find($request->role_id);
            if ($caller->isManager() && $role && $role->name === 'admin') {
                return response()->json(['error' => 'validation', 'message' => 'Managers cannot assign the admin role.'], 422);
            }
            $user->role_id = $request->role_id;
        }

        if (!$caller->isManager() && ($request->has('tenant_id') || $request->exists('tenant_id'))) {
            $user->tenant_id = $request->tenant_id;
        }

        if ($request->has('name')) {
            $user->name = $request->name;
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
     * Deactivate/Disable a user profile instead of deleting it (Admin or Manager).
     */
    public function deleteUser($id)
    {
        $request = request();
        if ($response = $this->requireAdminOrManager($request)) {
            return $response;
        }

        $caller = $request->user();

        if ($caller->id == $id) {
            return response()->json([
                'message' => 'Deletions denied: you cannot deactivate your active session profile.'
            ], 400);
        }

        $query = User::query();
        if ($caller->isManager()) {
            $query->where('tenant_id', $caller->tenant_id);
        }

        $user = $query->find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->is_active = false;
        $user->save();

        return response()->json(['message' => 'User account deactivated successfully.']);
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

    /**
     * Get list of sent template messages for a tenant in a billing month (Admin only).
     */
    public function getTenantTemplateMessages(Request $request, $tenantId)
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

        $billingMonthStr = $request->input('billing_month') ?: now()->format('Y-m');
        $month = Carbon::parse($billingMonthStr)->startOfMonth();
        $periodStart = $month->copy()->startOfMonth()->startOfDay();
        $periodEnd = $month->copy()->endOfMonth()->endOfDay();

        $excludeFailedTemplateMessages = function ($query) {
            $query->where(function ($nested) {
                $nested->whereNull('template_id')
                    ->orWhereNull('error_message');
            });
        };

        $messages = Message::withoutGlobalScopes()
            ->with([
                'template:id,name,category,billing_cost,channel_id',
                'conversation.contact:id,name,phone_number',
                'conversation.channel:id,display_name,phone_number'
            ])
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'outbound')
            ->whereNotNull('template_id')
            ->where('status', '!=', 'failed')
            ->where(function ($q) use ($periodStart, $periodEnd) {
                $q->whereBetween('sent_at', [$periodStart, $periodEnd])
                  ->orWhere(function ($q2) use ($periodStart, $periodEnd) {
                      $q2->whereNull('sent_at')
                         ->whereBetween('created_at', [$periodStart, $periodEnd]);
                  });
            })
            ->where($excludeFailedTemplateMessages)
            ->orderBy(DB::raw('COALESCE(sent_at, created_at)'), 'desc')
            ->get();

        $items = $messages->map(function ($msg) {
            $template = $msg->template;
            $category = strtoupper((string) ($template?->category ?? 'OTHER'));
            
            $agentRate = $template && $template->billing_cost !== null
                ? number_format((float) $template->billing_cost, 4, '.', '')
                : MessageTemplate::defaultAgentBillingCostForCategory($category);

            $metaRate = MessageTemplate::defaultAdminBillingCostForCategory($category);

            return [
                'id' => $msg->id,
                'template_id' => $msg->template_id,
                'template_name' => $template?->name ?? 'N/A',
                'category' => $category,
                'sent_at' => optional($msg->sent_at ?? $msg->created_at)->toDateTimeString(),
                'contact_name' => $msg->conversation?->contact?->name ?? 'N/A',
                'contact_phone' => $msg->conversation?->contact?->phone_number ?? 'N/A',
                'channel_name' => $msg->conversation?->channel?->display_name ?? 'N/A',
                'client_cost' => $agentRate,
                'meta_cost' => $metaRate,
                'status' => $msg->status,
                'message_preview' => mb_strimwidth((string) $msg->body, 0, 80, '...'),
            ];
        });

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'billing_month' => $month->format('Y-m'),
            'total_count' => $items->count(),
            'messages' => $items,
        ]);
    }

    /**
     * List contact categories for a tenant (Admin only).
     */
    public function listTenantContactCategories(Request $request, $tenantId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $categories = \App\Models\ContactCategory::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('contacts:id')
            ->withCount('contacts')
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    /**
     * Store a new contact category for a tenant (Admin only).
     */
    public function storeTenantContactCategory(Request $request, $tenantId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category = \App\Models\ContactCategory::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'description' => $request->description,
        ]);

        return response()->json($category, 201);
    }

    /**
     * Update an existing contact category (Admin only).
     */
    public function updateTenantContactCategory(Request $request, $id)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $category = \App\Models\ContactCategory::withoutGlobalScopes()->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $category->update([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        return response()->json($category);
    }

    /**
     * Delete a contact category (Admin only).
     */
    public function deleteTenantContactCategory($id)
    {
        if ($response = $this->requireAdmin(request())) {
            return $response;
        }

        $category = \App\Models\ContactCategory::withoutGlobalScopes()->findOrFail($id);
        $category->delete();

        return response()->json(['message' => 'Category deleted successfully.']);
    }

    /**
     * Sync contacts mapped to this category (Admin only).
     */
    public function syncTenantContactCategoryContacts(Request $request, $id)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $category = \App\Models\ContactCategory::withoutGlobalScopes()->findOrFail($id);
        
        $request->validate([
            'contact_ids' => 'present|array',
            'contact_ids.*' => 'integer',
        ]);

        // Verify that all contacts exist and belong to the same tenant as the category
        $invalidCount = \App\Models\Contact::withoutGlobalScopes()
            ->whereIn('id', $request->contact_ids)
            ->where('tenant_id', '!=', $category->tenant_id)
            ->count();

        if ($invalidCount > 0) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'Some contacts belong to a different tenant.'
            ], 403);
        }

        $category->contacts()->sync($request->contact_ids);

        return response()->json([
            'message' => 'Category contacts synchronized successfully.',
            'contacts_count' => $category->contacts()->count(),
        ]);
    }

    /**
     * List contacts for a tenant (Admin only).
     */
    public function listTenantContacts(Request $request, $tenantId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $query = \App\Models\Contact::withoutGlobalScopes()->where('tenant_id', $tenantId);

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        $contacts = $query->orderBy('name', 'asc')->paginate(15);

        return response()->json($contacts);
    }

    /**
     * Store a new contact for a tenant (Admin only).
     */
    public function storeTenantContact(Request $request, $tenantId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => [
                'required',
                'string',
                'max:30',
                'regex:/^\+?[1-9]\d{1,14}$/', 
                \Illuminate\Validation\Rule::unique('contacts')->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                }),
            ],
        ], [
            'phone_number.regex' => 'The phone number format must be E.164 (e.g. +96171000000).',
            'phone_number.unique' => 'A contact with this phone number already exists in this workspace.',
        ]);

        $contact = \App\Models\Contact::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'phone_number' => $request->phone_number,
            'added_via' => 'manual',
        ]);

        return response()->json([
            'message' => 'Contact created successfully.',
            'contact' => $contact
        ], 201);
    }

    /**
     * Update contact (Admin only).
     */
    public function updateTenantContact(Request $request, $id)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $contact = \App\Models\Contact::withoutGlobalScopes()->find($id);

        if (!$contact) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Contact not found.'
            ], 404);
        }

        $tenantId = $contact->tenant_id;

        $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => [
                'required',
                'string',
                'max:30',
                'regex:/^\+?[1-9]\d{1,14}$/', 
                \Illuminate\Validation\Rule::unique('contacts')->ignore($id)->where(function ($query) use ($tenantId) {
                    return $query->where('tenant_id', $tenantId);
                }),
            ],
        ], [
            'phone_number.regex' => 'The phone number format must be E.164 (e.g. +96171000000).',
            'phone_number.unique' => 'A contact with this phone number already exists in this workspace.',
        ]);

        $contact->update($request->only(['name', 'phone_number']));

        return response()->json([
            'message' => 'Contact updated successfully.',
            'contact' => $contact
        ]);
    }

    /**
     * Delete contact (Admin only).
     */
    public function deleteTenantContact($id)
    {
        if ($response = $this->requireAdmin(request())) {
            return $response;
        }

        $contact = \App\Models\Contact::withoutGlobalScopes()->find($id);

        if (!$contact) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Contact not found.'
            ], 404);
        }

        $contact->delete();

        return response()->json([
            'message' => 'Contact deleted successfully.'
        ]);
    }

    /**
     * List message templates for a tenant (Admin only).
     */
    public function listTenantTemplates(Request $request, $tenantId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $templates = \App\Models\MessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('channel')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($templates);
    }

    /**
     * Delete a template (Admin only).
     */
    public function deleteTenantTemplate($id)
    {
        if ($response = $this->requireAdmin(request())) {
            return $response;
        }

        $template = \App\Models\MessageTemplate::withoutGlobalScopes()->find($id);

        if (!$template) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Template not found.'
            ], 404);
        }

        $channel = WabaChannel::withoutGlobalScopes()->find($template->channel_id);

        if ($channel && $channel->decrypted_token && $channel->waba_id) {
            try {
                $metaService = resolve(\App\Services\MetaApiService::class);
                $metaService->deleteMessageTemplate(
                    $channel->decrypted_token,
                    $channel->waba_id,
                    $template->name
                );
            } catch (\Exception $e) {
                // Log failure or handle missing Meta template
            }
        }

        $template->delete();

        return response()->json([
            'message' => 'Template deleted successfully.'
        ]);
    }

    /**
     * Sync templates for a tenant specifically (Admin only).
     */
    public function syncTenantTemplates(Request $request, $tenantId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

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
            $metaService = resolve(\App\Services\MetaApiService::class);
            $metaData = $metaService->fetchMessageTemplates(
                $channel->decrypted_token,
                $channel->waba_id
            );

            $syncedCount = 0;

            foreach ($metaData['data'] ?? [] as $metaTpl) {
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
                $lang = $metaTpl['language'];

                $tplData = [
                    'meta_template_id' => $metaTpl['id'] ?? null,
                    'status' => $status,
                    'category' => $metaTpl['category'] ?? 'UTILITY',
                    'billing_cost' => \App\Models\MessageTemplate::defaultBillingCostForCategory($metaTpl['category'] ?? 'UTILITY'),
                    'language' => $lang,
                    'header_type' => $headerType,
                    'header_content' => $headerContent,
                    'body' => $bodyText,
                    'footer' => $footerText,
                    'variables' => $variables,
                    'rejection_reason' => $metaTpl['rejected_reason'] ?? null,
                    'approved_at' => $status === 'APPROVED' ? now() : null,
                ];

                $localTpl = \App\Models\MessageTemplate::withoutGlobalScopes()
                    ->where('tenant_id', $channel->tenant_id)
                    ->where('name', $metaTpl['name'])
                    ->first();

                if ($localTpl) {
                    $localTpl->update($tplData);
                } else {
                    \App\Models\MessageTemplate::create(array_merge($tplData, [
                        'tenant_id' => $channel->tenant_id,
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

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'sync_failed',
                'message' => 'Failed to sync templates with Meta: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List all tenants with their AI template audit daily usage and limits (Admin only).
     */
    public function listTenantAiUsages(Request $request)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $query = Tenant::query();

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->input('per_page', 15);
        $tenants = $query->orderBy('name', 'asc')->paginate($perPage);

        $tenants->getCollection()->transform(function ($tenant) {
            $tenant->ai_usage = TenantAiUsage::getTenantSummary($tenant->id);
            return $tenant;
        });

        $today = Carbon::today()->toDateString();
        $totalUsedToday = (int) TenantAiUsage::where('usage_date', $today)->sum('requests_count');
        $totalPromptTokensToday = (int) TenantAiUsage::where('usage_date', $today)->sum('prompt_tokens');
        $totalCompletionTokensToday = (int) TenantAiUsage::where('usage_date', $today)->sum('completion_tokens');
        $totalTokensToday = (int) TenantAiUsage::where('usage_date', $today)->sum('total_tokens');
        $totalCostToday = (float) TenantAiUsage::where('usage_date', $today)->sum('estimated_cost');

        $totalLifetimeAudits = (int) TenantAiUsage::sum('requests_count');
        $totalLifetimeTokens = (int) TenantAiUsage::sum('total_tokens');
        $totalLifetimeCost = (float) TenantAiUsage::sum('estimated_cost');

        $activeTenantsToday = TenantAiUsage::where('usage_date', $today)->where('requests_count', '>', 0)->count();

        return response()->json([
            'tenants' => $tenants,
            'metrics' => [
                'total_used_today' => $totalUsedToday,
                'active_tenants_today' => $activeTenantsToday,
                'total_tokens_today' => $totalTokensToday,
                'total_prompt_tokens_today' => $totalPromptTokensToday,
                'total_completion_tokens_today' => $totalCompletionTokensToday,
                'total_estimated_cost_today' => number_format($totalCostToday, 6, '.', ''),
                'total_lifetime_audits' => $totalLifetimeAudits,
                'total_lifetime_tokens' => $totalLifetimeTokens,
                'total_lifetime_estimated_cost' => number_format($totalLifetimeCost, 6, '.', ''),
                'free_tier_daily_quota' => 1500,
                'free_tier_remaining_today' => max(0, 1500 - $totalUsedToday),
                'default_daily_limit' => TenantAiUsage::DEFAULT_DAILY_LIMIT,
            ]
        ]);
    }

    /**
     * Update a tenant's daily AI audit limit (Admin only).
     */
    public function updateTenantAiLimit(Request $request, $tenantId)
    {
        if ($response = $this->requireAdmin($request)) {
            return $response;
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json(['error' => 'not_found', 'message' => 'Tenant not found.'], 404);
        }

        $request->validate([
            'daily_limit' => 'required|integer|min:1|max:10000',
        ]);

        $limit = (int) $request->daily_limit;
        $usage = TenantAiUsage::forTenantToday($tenant->id, $limit);
        $usage->daily_limit = $limit;
        $usage->save();

        return response()->json([
            'message' => 'Tenant AI daily limit updated successfully.',
            'ai_usage' => TenantAiUsage::getTenantSummary($tenant->id),
        ]);
    }
}
