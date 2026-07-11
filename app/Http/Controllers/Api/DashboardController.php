<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WabaChannel;
use App\Models\Message;
use App\Models\Conversation;
use App\Models\MessageTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get aggregated metrics and cost estimations for the tenant.
     */
    public function getStats(Request $request)
    {
        $tenantId = $request->get('tenant_id');
        
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

        // Helper function to compile billing cost parameters for any date range
        $compileBillingForRange = function ($start, $end) use ($tenantId) {
            $marketingCount = Message::where('messages.tenant_id', $tenantId)
                ->where('messages.direction', 'outbound')
                ->whereBetween('messages.created_at', [$start, $end])
                ->whereNull('messages.error_code')
                ->join('message_templates', 'messages.template_id', '=', 'message_templates.id')
                ->where('message_templates.category', 'MARKETING')
                ->distinct('messages.conversation_id')
                ->count('messages.conversation_id');

            $utilityCount = Message::where('messages.tenant_id', $tenantId)
                ->where('messages.direction', 'outbound')
                ->whereBetween('messages.created_at', [$start, $end])
                ->whereNull('messages.error_code')
                ->join('message_templates', 'messages.template_id', '=', 'message_templates.id')
                ->where('message_templates.category', 'UTILITY')
                ->distinct('messages.conversation_id')
                ->count('messages.conversation_id');

            $inboundConversationIds = Message::where('tenant_id', $tenantId)
                ->where('direction', 'inbound')
                ->whereBetween('created_at', [$start, $end])
                ->pluck('conversation_id')
                ->unique()
                ->toArray();

            $serviceCount = 0;
            if (!empty($inboundConversationIds)) {
                $serviceCount = Conversation::whereIn('id', $inboundConversationIds)
                    ->whereDoesntHave('messages', function ($query) use ($start, $end) {
                        $query->where('direction', 'outbound')
                            ->whereNotNull('template_id')
                            ->whereBetween('created_at', [$start, $end]);
                    })
                    ->count();
            }

            $marketingRate = 0.04;
            $utilityRate = 0.015;
            $serviceRate = 0.01;

            $marketingCost = $marketingCount * $marketingRate;
            $utilityCost = $utilityCount * $utilityRate;
            
            // First 1000 Service conversations per WABA per month are free
            $freeTierRemaining = max(0, 1000 - $serviceCount);
            $billableServiceCount = max(0, $serviceCount - 1000);
            $serviceCost = $billableServiceCount * $serviceRate;

            $totalCost = $marketingCost + $utilityCost + $serviceCost;

            return [
                'marketing_conversations' => $marketingCount,
                'utility_conversations' => $utilityCount,
                'service_conversations' => $serviceCount,
                'marketing_cost' => $marketingCost,
                'utility_cost' => $utilityCost,
                'service_cost' => $serviceCost,
                'free_tier_remaining' => $freeTierRemaining,
                'total_estimated_cost' => $totalCost,
            ];
        };

        // Monthly stats for the target month
        $monthlyMessagesCount = Message::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$startOfTargetMonth, $endOfTargetMonth])
            ->count();

        $monthlyInbound = Message::where('tenant_id', $tenantId)
            ->where('direction', 'inbound')
            ->whereBetween('created_at', [$startOfTargetMonth, $endOfTargetMonth])
            ->count();

        $monthlyOutbound = Message::where('tenant_id', $tenantId)
            ->where('direction', 'outbound')
            ->whereBetween('created_at', [$startOfTargetMonth, $endOfTargetMonth])
            ->count();

        $statusCounts = Message::where('tenant_id', $tenantId)
            ->where('direction', 'outbound')
            ->whereBetween('created_at', [$startOfTargetMonth, $endOfTargetMonth])
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $targetBilling = $compileBillingForRange($startOfTargetMonth, $endOfTargetMonth);

        // Compile historical list of past 6 months (including the current month)
        $history = [];
        for ($i = 0; $i < 6; $i++) {
            $month = Carbon::now()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();
            $billingData = $compileBillingForRange($start, $end);
            
            $history[] = [
                'month_name' => $month->format('F Y'),
                'month_key' => $month->format('Y-m'),
                'marketing_conversations' => $billingData['marketing_conversations'],
                'utility_conversations' => $billingData['utility_conversations'],
                'service_conversations' => $billingData['service_conversations'],
                'free_tier_remaining' => $billingData['free_tier_remaining'],
                'total_estimated_cost' => $billingData['total_estimated_cost'],
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
            'billing' => array_merge($targetBilling, ['currency' => 'USD']),
            'history' => $history
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
