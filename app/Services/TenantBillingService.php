<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Tenant;
use App\Models\TenantBillingSnapshot;
use App\Models\WabaChannel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantBillingService
{
    public const FREE_TIER_LIMIT = 0;
    public const BILLABLE_WINDOW_RATE = '0.015';

    public function getMonthlySnapshotSummary(Tenant $tenant, Carbon $month): array
    {
        $billingMonth = $month->copy()->startOfMonth();

        if (Schema::hasTable('tenant_billing_snapshots')) {
            if ($billingMonth->isCurrentMonth()) {
                return $this->snapshotToArray($this->syncMonthlySnapshot($tenant, $billingMonth));
            }

            $snapshot = TenantBillingSnapshot::query()
                ->where('tenant_id', $tenant->id)
                ->whereDate('billing_month', $billingMonth->toDateString())
                ->first();

            if ($snapshot) {
                return $this->snapshotToArray($snapshot);
            }
        }

        return $this->calculateMonthSummary($tenant, $billingMonth);
    }

    public function syncMonthlySnapshot(Tenant $tenant, Carbon $month): TenantBillingSnapshot
    {
        if (!Schema::hasTable('tenant_billing_snapshots')) {
            throw new \RuntimeException('tenant_billing_snapshots table is missing. Run migrations before syncing billing snapshots.');
        }

        $billingMonthStr = $month->copy()->startOfMonth()->toDateString();
        $summary = $this->calculateMonthSummary($tenant, $month->copy()->startOfMonth());

        $existing = TenantBillingSnapshot::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereDate('billing_month', $billingMonthStr)
            ->first();

        $updateData = [
            'period_start' => $summary['period_start'],
            'period_end' => $summary['period_end'],
            'conversation_sessions_count' => $summary['conversation_sessions_count'],
            'free_tier_limit' => $summary['free_tier_limit'],
            'free_tier_remaining' => $summary['free_tier_remaining'],
            'billable_conversations_count' => $summary['billable_conversations_count'],
            'billable_window_rate' => $summary['billable_window_rate'],
            'billable_conversation_cost' => $summary['billable_conversation_cost'],
            'template_cost_total' => $summary['template_cost_total'],
            'total_estimated_cost' => $summary['total_estimated_cost'],
            'is_approximate' => $summary['is_approximate'],
            'template_breakdown' => $summary['template_breakdown'],
            'calculated_at' => $summary['calculated_at'],
            'meta_billable_window_rate' => $summary['meta_billable_window_rate'],
            'meta_billable_conversation_cost' => $summary['meta_billable_conversation_cost'],
            'meta_template_cost_total' => $summary['meta_template_cost_total'],
            'meta_total_estimated_cost' => $summary['meta_total_estimated_cost'],
            'meta_template_breakdown' => $summary['meta_template_breakdown'],
            'channels_breakdown' => $summary['channels_breakdown'] ?? [],
        ];

        if ($existing) {
            $updateData['payment_status'] = $existing->payment_status ?? 'unpaid';
            $updateData['paid_at'] = $existing->paid_at;
            $updateData['amount_paid'] = $existing->amount_paid ?? 0.0000;
            $updateData['payment_notes'] = $existing->payment_notes;
        }

        return TenantBillingSnapshot::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'billing_month' => $billingMonthStr,
            ],
            $updateData
        );
    }

    public function calculateMonthSummary(Tenant $tenant, Carbon $month): array
    {
        $periodStart = $month->copy()->startOfMonth()->startOfDay();
        $periodEnd = $month->copy()->endOfMonth()->endOfDay();
        $excludeFailedTemplateMessages = function ($query) {
            $query->where(function ($nested) {
                $nested->whereNull('template_id')
                    ->orWhereNull('error_message');
            });
        };

        // Resolve active tenant channels
        $channels = WabaChannel::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get(['id', 'display_name', 'phone_number']);

        $channelMap = [];
        foreach ($channels as $ch) {
            $channelMap[(int) $ch->id] = [
                'channel_id' => (int) $ch->id,
                'display_name' => $ch->display_name ?: ('Channel #' . $ch->id),
                'phone_number' => $ch->phone_number ?: 'N/A',
                'conversation_sessions_count' => 0,
                'billable_conversations_count' => 0,
                'billable_conversation_cost' => '0.0000',
                'template_cost_total' => '0.0000',
                'total_estimated_cost' => '0.0000',
                'meta_billable_conversation_cost' => '0.0000',
                'meta_template_cost_total' => '0.0000',
                'meta_total_estimated_cost' => '0.0000',
            ];
        }

        // Map conversations to channel_id
        $conversations = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->pluck('channel_id', 'id');

        $inboundMessages = Message::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'inbound')
            ->where('status', '!=', 'failed')
            ->where(function ($q) use ($periodStart, $periodEnd) {
                $q->whereBetween('sent_at', [$periodStart, $periodEnd])
                  ->orWhere(function ($q2) use ($periodStart, $periodEnd) {
                      $q2->whereNull('sent_at')
                         ->whereBetween('created_at', [$periodStart, $periodEnd]);
                  });
            })
            ->orderBy('conversation_id')
            ->orderBy(DB::raw('COALESCE(sent_at, created_at)'))
            ->orderBy('id')
            ->get([
                'id',
                'conversation_id',
                'sent_at',
                'created_at',
            ]);

        $previousInboundAt = Message::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'inbound')
            ->where('status', '!=', 'failed')
            ->where(function ($q) use ($periodStart) {
                $q->where('sent_at', '<', $periodStart)
                  ->orWhere(function ($q2) use ($periodStart) {
                      $q2->whereNull('sent_at')
                         ->where('created_at', '<', $periodStart);
                  });
            })
            ->select('conversation_id', DB::raw('MAX(COALESCE(sent_at, created_at)) as last_inbound_at'))
            ->groupBy('conversation_id')
            ->pluck('last_inbound_at', 'conversation_id');

        $windowStartByConversation = [];
        foreach ($previousInboundAt as $conversationId => $lastInboundAt) {
            $windowStartByConversation[(int) $conversationId] = Carbon::parse($lastInboundAt);
        }

        $templateBreakdown = [
            'marketing' => ['count' => 0, 'cost' => '0.0000', 'rate' => MessageTemplate::defaultAgentBillingCostForCategory('MARKETING')],
            'utility' => ['count' => 0, 'cost' => '0.0000', 'rate' => MessageTemplate::defaultAgentBillingCostForCategory('UTILITY')],
            'authentication' => ['count' => 0, 'cost' => '0.0000', 'rate' => MessageTemplate::defaultAgentBillingCostForCategory('AUTHENTICATION')],
            'other' => ['count' => 0, 'cost' => '0.0000', 'rate' => '0.0000'],
        ];

        $metaTemplateBreakdown = [
            'marketing' => ['count' => 0, 'cost' => '0.0000', 'rate' => MessageTemplate::defaultAdminBillingCostForCategory('MARKETING')],
            'utility' => ['count' => 0, 'cost' => '0.0000', 'rate' => MessageTemplate::defaultAdminBillingCostForCategory('UTILITY')],
            'authentication' => ['count' => 0, 'cost' => '0.0000', 'rate' => MessageTemplate::defaultAdminBillingCostForCategory('AUTHENTICATION')],
            'other' => ['count' => 0, 'cost' => '0.0000', 'rate' => '0.0000'],
        ];

        $conversationSessions = 0;
        $billableWindowRate = number_format((float) self::BILLABLE_WINDOW_RATE, 4, '.', '');
        $templateCostTotal = '0.0000';
        $metaTemplateCostTotal = '0.0000';

        foreach ($inboundMessages as $message) {
            $sentAt = Carbon::parse($message->sent_at ?? $message->created_at);
            $conversationId = (int) $message->conversation_id;
            $channelId = isset($conversations[$conversationId]) ? (int) $conversations[$conversationId] : null;
            $windowStart = $windowStartByConversation[$conversationId] ?? null;

            if (!$windowStart || $sentAt->greaterThanOrEqualTo($windowStart->copy()->addHours(24))) {
                $conversationSessions++;
                $windowStartByConversation[$conversationId] = $sentAt;

                if ($channelId && isset($channelMap[$channelId])) {
                    $channelMap[$channelId]['conversation_sessions_count']++;
                }
            }
        }

        $templateMessages = Message::withoutGlobalScopes()
            ->with(['template:id,category,billing_cost,channel_id'])
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'outbound')
            ->where('status', '!=', 'failed')
            ->where(function ($q) use ($periodStart, $periodEnd) {
                $q->whereBetween('sent_at', [$periodStart, $periodEnd])
                  ->orWhere(function ($q2) use ($periodStart, $periodEnd) {
                      $q2->whereNull('sent_at')
                         ->whereBetween('created_at', [$periodStart, $periodEnd]);
                  });
            })
            ->where($excludeFailedTemplateMessages)
            ->orderBy('conversation_id')
            ->orderBy(DB::raw('COALESCE(sent_at, created_at)'))
            ->orderBy('id')
            ->get([
                'id',
                'conversation_id',
                'template_id',
                'sent_at',
                'created_at',
                'status',
                'error_message',
            ]);

        foreach ($templateMessages as $message) {
            $template = $message->template;
            $conversationId = (int) $message->conversation_id;
            $channelId = isset($conversations[$conversationId]) 
                ? (int) $conversations[$conversationId] 
                : ($template ? (int) $template->channel_id : null);

            if (!$template) {
                $templateBreakdown['other']['count']++;
                $metaTemplateBreakdown['other']['count']++;
                continue;
            }

            $category = strtoupper((string) $template->category);
            $bucket = match ($category) {
                'MARKETING' => 'marketing',
                'UTILITY' => 'utility',
                'AUTHENTICATION' => 'authentication',
                default => 'other',
            };

            // Agent rate: use stored template cost or default agent rate
            $agentRate = $template->billing_cost !== null
                ? number_format((float) $template->billing_cost, 4, '.', '')
                : MessageTemplate::defaultAgentBillingCostForCategory($category);

            $templateBreakdown[$bucket]['count']++;
            $templateBreakdown[$bucket]['rate'] = $agentRate;
            $templateBreakdown[$bucket]['cost'] = number_format(
                (float) $templateBreakdown[$bucket]['cost'] + (float) $agentRate,
                4,
                '.',
                ''
            );
            $templateCostTotal = number_format((float) $templateCostTotal + (float) $agentRate, 4, '.', '');

            // Meta rate: always use default admin rate
            $metaRate = MessageTemplate::defaultAdminBillingCostForCategory($category);

            $metaTemplateBreakdown[$bucket]['count']++;
            $metaTemplateBreakdown[$bucket]['rate'] = $metaRate;
            $metaTemplateBreakdown[$bucket]['cost'] = number_format(
                (float) $metaTemplateBreakdown[$bucket]['cost'] + (float) $metaRate,
                4,
                '.',
                ''
            );
            $metaTemplateCostTotal = number_format((float) $metaTemplateCostTotal + (float) $metaRate, 4, '.', '');

            if ($channelId && isset($channelMap[$channelId])) {
                $channelMap[$channelId]['template_cost_total'] = number_format(
                    (float) $channelMap[$channelId]['template_cost_total'] + (float) $agentRate,
                    4,
                    '.',
                    ''
                );
                $channelMap[$channelId]['meta_template_cost_total'] = number_format(
                    (float) $channelMap[$channelId]['meta_template_cost_total'] + (float) $metaRate,
                    4,
                    '.',
                    ''
                );
            }
        }

        // Format per-channel breakdown metrics
        foreach ($channelMap as $cId => &$cData) {
            $cSessions = (int) $cData['conversation_sessions_count'];
            $cData['billable_conversations_count'] = $cSessions;
            $cData['billable_conversation_cost'] = number_format($cSessions * (float) $billableWindowRate, 4, '.', '');
            $cData['meta_billable_conversation_cost'] = number_format($cSessions * 0.01, 4, '.', '');
            $cData['total_estimated_cost'] = number_format(
                (float) $cData['template_cost_total'] + (float) $cData['billable_conversation_cost'],
                4,
                '.',
                ''
            );
            $cData['meta_total_estimated_cost'] = number_format(
                (float) $cData['meta_template_cost_total'] + (float) $cData['meta_billable_conversation_cost'],
                4,
                '.',
                ''
            );
        }
        unset($cData);

        $freeTierRemaining = max(0, self::FREE_TIER_LIMIT - $conversationSessions);
        $billableConversations = max(0, $conversationSessions - self::FREE_TIER_LIMIT);
        $billableConversationCost = number_format($billableConversations * (float) $billableWindowRate, 4, '.', '');
        $totalEstimatedCost = number_format((float) $templateCostTotal + (float) $billableConversationCost, 4, '.', '');

        // Meta (Facebook) actual expenses
        $metaBillableWindowRate = number_format(0.01, 4, '.', '');
        $metaBillableConversationCost = number_format($conversationSessions * 0.01, 4, '.', '');
        $metaTotalEstimatedCost = number_format((float) $metaTemplateCostTotal + (float) $metaBillableConversationCost, 4, '.', '');

        return [
            'billing_month' => $month->copy()->startOfMonth()->toDateString(),
            'period_start' => $periodStart->toDateTimeString(),
            'period_end' => $periodEnd->toDateTimeString(),
            'conversation_sessions_count' => $conversationSessions,
            'free_tier_limit' => self::FREE_TIER_LIMIT,
            'free_tier_remaining' => $freeTierRemaining,
            'billable_conversations_count' => $billableConversations,
            'billable_window_rate' => $billableWindowRate,
            'billable_conversation_cost' => $billableConversationCost,
            'template_cost_total' => $templateCostTotal,
            'total_estimated_cost' => $totalEstimatedCost,
            'is_approximate' => true,
            'template_breakdown' => $templateBreakdown,
            'calculated_at' => now()->toDateTimeString(),

            'meta_billable_window_rate' => $metaBillableWindowRate,
            'meta_billable_conversation_cost' => $metaBillableConversationCost,
            'meta_template_cost_total' => $metaTemplateCostTotal,
            'meta_total_estimated_cost' => $metaTotalEstimatedCost,
            'meta_template_breakdown' => $metaTemplateBreakdown,
            'channels_breakdown' => array_values($channelMap),
            'channels_count' => count($channelMap),
            'payment_status' => 'unpaid',
            'paid_at' => null,
            'amount_paid' => '0.0000',
            'payment_notes' => null,
        ];
    }

    private function snapshotToArray(TenantBillingSnapshot $snapshot): array
    {
        return [
            'billing_month' => optional($snapshot->billing_month)->toDateString(),
            'period_start' => optional($snapshot->period_start)->toDateTimeString(),
            'period_end' => optional($snapshot->period_end)->toDateTimeString(),
            'conversation_sessions_count' => $snapshot->conversation_sessions_count,
            'free_tier_limit' => $snapshot->free_tier_limit,
            'free_tier_remaining' => $snapshot->free_tier_remaining,
            'billable_conversations_count' => $snapshot->billable_conversations_count,
            'billable_window_rate' => number_format((float) $snapshot->billable_window_rate, 4, '.', ''),
            'billable_conversation_cost' => number_format((float) $snapshot->billable_conversation_cost, 4, '.', ''),
            'template_cost_total' => number_format((float) $snapshot->template_cost_total, 4, '.', ''),
            'total_estimated_cost' => number_format((float) $snapshot->total_estimated_cost, 4, '.', ''),
            'is_approximate' => (bool) $snapshot->is_approximate,
            'template_breakdown' => $snapshot->template_breakdown ?? [],
            'calculated_at' => optional($snapshot->calculated_at)->toDateTimeString(),

            'meta_billable_window_rate' => number_format((float) ($snapshot->meta_billable_window_rate ?? 0.0100), 4, '.', ''),
            'meta_billable_conversation_cost' => number_format((float) ($snapshot->meta_billable_conversation_cost ?? 0.0000), 4, '.', ''),
            'meta_template_cost_total' => number_format((float) ($snapshot->meta_template_cost_total ?? 0.0000), 4, '.', ''),
            'meta_total_estimated_cost' => number_format((float) ($snapshot->meta_total_estimated_cost ?? 0.0000), 4, '.', ''),
            'meta_template_breakdown' => $snapshot->meta_template_breakdown ?? [],
            'channels_breakdown' => $snapshot->channels_breakdown ?? [],
            'channels_count' => is_array($snapshot->channels_breakdown) ? count($snapshot->channels_breakdown) : 0,
            'payment_status' => $snapshot->payment_status ?? 'unpaid',
            'paid_at' => optional($snapshot->paid_at)->toDateTimeString(),
            'amount_paid' => number_format((float) ($snapshot->amount_paid ?? 0.0000), 4, '.', ''),
            'payment_notes' => $snapshot->payment_notes,
        ];
    }
}
