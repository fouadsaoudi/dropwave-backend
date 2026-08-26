<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WabaChannel;
use App\Models\Message;
use App\Models\Conversation;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\TenantBillingSnapshot;
use App\Services\TenantBillingService;

class DashboardController extends Controller
{
    /**
     * Get aggregated metrics and cost estimations for the tenant.
     */
    public function getStats(Request $request, TenantBillingService $billingService)
    {
        $tenantId = $request->get('tenant_id');
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return response()->json([
                'error' => 'tenant_not_found',
                'message' => 'Tenant not found.'
            ], 404);
        }
        
        $allTimeQuery = $request->query('all_time');
        
        if ($allTimeQuery === 'true' || $allTimeQuery === '1') {
            $startOfTargetMonth = Carbon::parse('2020-01-01')->startOfDay();
            $endOfTargetMonth = Carbon::now()->addCentury()->endOfDay();
        } else {
            // Parse requested start_date and end_date, or fall back to month, or default to current month
            $startDateQuery = $request->query('start_date');
            $endDateQuery = $request->query('end_date');

            if ($startDateQuery && $endDateQuery) {
                try {
                    $startOfTargetMonth = Carbon::createFromFormat('Y-m-d', $startDateQuery)->startOfDay();
                    $endOfTargetMonth = Carbon::createFromFormat('Y-m-d', $endDateQuery)->endOfDay();
                } catch (\Exception $e) {
                    $startOfTargetMonth = Carbon::now()->startOfMonth();
                    $endOfTargetMonth = Carbon::now()->endOfMonth();
                }
            } else {
                $monthQuery = $request->query('month');
                if ($monthQuery && preg_match('/^\d{4}-\d{2}$/', $monthQuery)) {
                    $currentMonth = Carbon::createFromFormat('Y-m', $monthQuery);
                } else {
                    $currentMonth = Carbon::now();
                }
                $startOfTargetMonth = $currentMonth->copy()->startOfMonth();
                $endOfTargetMonth = $currentMonth->copy()->endOfMonth();
            }
        }

        // 1. Channel stats (Global, not month-dependent)
        $connectedWabas = WabaChannel::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        // 2. Active conversations count (open)
        $activeConversations = Conversation::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->count();

        // 3. Unread conversations
        $unreadConversations = Conversation::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->where('unread_count', '>', 0)
            ->count();

        // Monthly stats for the target month
        $monthlyMessagesCount = Message::withTrashed()->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$startOfTargetMonth, $endOfTargetMonth])
            ->count();

        $monthlyInbound = Message::withTrashed()->where('tenant_id', $tenantId)
            ->where('direction', 'inbound')
            ->whereBetween('created_at', [$startOfTargetMonth, $endOfTargetMonth])
            ->count();

        $monthlyOutbound = Message::withTrashed()->where('tenant_id', $tenantId)
            ->where('direction', 'outbound')
            ->whereBetween('created_at', [$startOfTargetMonth, $endOfTargetMonth])
            ->count();

        $statusCounts = Message::withTrashed()->where('tenant_id', $tenantId)
            ->where('direction', 'outbound')
            ->whereBetween('created_at', [$startOfTargetMonth, $endOfTargetMonth])
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $currentBilling = $billingService->getMonthlySnapshotSummary($tenant, Carbon::now());

        // Fast compilation of historical 6 months using a single indexed query
        $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();
        $snapshots = TenantBillingSnapshot::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('billing_month', '>=', $sixMonthsAgo->toDateString())
            ->get()
            ->keyBy(fn ($s) => Carbon::parse($s->billing_month)->format('Y-m'));

        $history = [];
        for ($i = 0; $i < 6; $i++) {
            $month = Carbon::now()->subMonths($i);
            $monthKey = $month->format('Y-m');
            $snapshot = $snapshots->get($monthKey);

            if ($snapshot) {
                $history[] = [
                    'month_name' => $month->format('F Y'),
                    'month_key' => $monthKey,
                    'conversation_sessions_count' => (int) $snapshot->conversation_sessions_count,
                    'free_tier_remaining' => (int) $snapshot->free_tier_remaining,
                    'total_estimated_cost' => number_format((float) $snapshot->total_estimated_cost, 4, '.', ''),
                ];
            } elseif ($month->isCurrentMonth()) {
                $history[] = [
                    'month_name' => $month->format('F Y'),
                    'month_key' => $monthKey,
                    'conversation_sessions_count' => (int) ($currentBilling['conversation_sessions_count'] ?? 0),
                    'free_tier_remaining' => (int) ($currentBilling['free_tier_remaining'] ?? 1000),
                    'total_estimated_cost' => (string) ($currentBilling['total_estimated_cost'] ?? '0.0000'),
                ];
            } else {
                $history[] = [
                    'month_name' => $month->format('F Y'),
                    'month_key' => $monthKey,
                    'conversation_sessions_count' => 0,
                    'free_tier_remaining' => 1000,
                    'total_estimated_cost' => '0.0000',
                ];
            }
        }

        $deliveryStats = null;
        if ($tenant->isDeliveryCoordination()) {
            $totalOrders = DB::table('orders')->where('tenant_id', $tenantId)->count();
            $pendingOrders = DB::table('orders')->where('tenant_id', $tenantId)->where('status', 'pending')->count();
            $deliveredOrders = DB::table('orders')->where('tenant_id', $tenantId)->where('status', 'delivered')->count();

            // Driver breakdown of delivered orders
            $driverBreakdown = DB::table('orders')
                ->join('drivers', 'orders.driver_id', '=', 'drivers.id')
                ->where('orders.tenant_id', $tenantId)
                ->where('orders.status', 'delivered')
                ->select('drivers.name', DB::raw('count(orders.id) as count'))
                ->groupBy('drivers.name')
                ->get();

            $deliveryStats = [
                'total_orders' => $totalOrders,
                'pending_orders' => $pendingOrders,
                'delivered_orders' => $deliveredOrders,
                'driver_breakdown' => $driverBreakdown
            ];
        }

        return response()->json([
            'channels_count' => $connectedWabas,
            'active_conversations' => $activeConversations,
            'unread_conversations' => $unreadConversations,
            'monthly_messages_count' => $monthlyMessagesCount,
            'monthly_inbound' => $monthlyInbound,
            'monthly_outbound' => $monthlyOutbound,
            'status_counts' => [
                'sent' => $statusCounts['sent'] ?? 0,
                'delivered' => $statusCounts['delivered'] ?? 0,
                'read' => $statusCounts['read'] ?? 0,
                'failed' => $statusCounts['failed'] ?? 0,
            ],
            'selected_month' => $startOfTargetMonth->format('Y-m'),
            'selected_month_name' => $startOfTargetMonth->format('F Y'),
            'start_date' => $startOfTargetMonth->format('Y-m-d'),
            'end_date' => $endOfTargetMonth->format('Y-m-d'),
            'billing' => array_merge($currentBilling, ['currency' => 'USD']),
            'history' => $history,
            'delivery_stats' => $deliveryStats
        ]);
    }

    /**
     * Recalculate the current tenant billing snapshot and return the latest estimate.
     */
    public function refreshEstimationDetail(Request $request, TenantBillingService $billingService)
    {
        $tenantId = $request->get('tenant_id');
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return response()->json([
                'error' => 'tenant_not_found',
                'message' => 'Tenant not found.'
            ], 404);
        }

        $snapshot = $billingService->syncMonthlySnapshot($tenant, Carbon::now());

        return response()->json([
            'message' => 'Billing estimate refreshed successfully.',
            'billing' => array_merge($billingService->getMonthlySnapshotSummary($tenant, Carbon::now()), [
                'currency' => 'USD',
                'snapshot_id' => $snapshot->id,
            ]),
        ]);
    }

    /**
     * Get the latest 15 message events as an activity log.
     */
    public function getActivityFeed(Request $request)
    {
        $tenantId = $request->get('tenant_id');

        $messages = Message::where('tenant_id', $tenantId)
            ->with(['conversation.contact'])
            ->orderBy('id', 'desc')
            ->take(15)
            ->get();

        $feed = $messages->map(function ($msg) {
            $contactName = $msg->conversation->contact->name ?? $msg->conversation->contact->phone_number ?? 'Customer';
            
            $action = 'sent a message';
            $type = 'info';

            if ($msg->direction === 'inbound') {
                $action = "replied: \"{$msg->body}\"";
                $type = 'inbound';
            } else {
                if ($msg->status === 'failed') {
                    $action = "failed to deliver to {$contactName} (⚠️ {$msg->error_message})";
                    $type = 'failed';
                } elseif ($msg->status === 'read') {
                    $action = "read your message";
                    $type = 'read';
                } elseif ($msg->status === 'delivered') {
                    $action = "received your message";
                    $type = 'delivered';
                } else {
                    $action = "sent a message: \"{$msg->body}\"";
                    $type = 'sent';
                }
            }

            return [
                'id' => $msg->id,
                'contact_name' => $contactName,
                'contact_phone' => $msg->conversation->contact->phone_number ?? '',
                'action' => $action,
                'type' => $type,
                'timestamp' => $msg->created_at->toIso8601String(),
            ];
        });

        return response()->json($feed);
    }
}
