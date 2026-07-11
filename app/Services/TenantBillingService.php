<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Tenant;
use App\Models\TenantBillingSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantBillingService
{
    public const FREE_TIER_LIMIT = 1000;
    public const BILLABLE_WINDOW_RATE = '0.01';

    public function getMonthlySnapshotSummary(Tenant $tenant, Carbon $month): array
    {
        $billingMonth = $month->copy()->startOfMonth();
        if (Schema::hasTable('tenant_billing_snapshots')) {
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

        $summary = $this->calculateMonthSummary($tenant, $month->copy()->startOfMonth());

        return TenantBillingSnapshot::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'billing_month' => $summary['billing_month'],
            ],
            [
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
            ]
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

        $inboundMessages = Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'inbound')
            ->where('status', '!=', 'failed')
            ->whereBetween('sent_at', [$periodStart, $periodEnd])
            ->orderBy('conversation_id')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get([
                'id',
                'conversation_id',
                'sent_at',
            ]);

        $previousInboundAt = Message::query()
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'inbound')
            ->where('status', '!=', 'failed')
            ->where('sent_at', '<', $periodStart)
            ->select('conversation_id', DB::raw('MAX(sent_at) as last_inbound_at'))
            ->groupBy('conversation_id')
            ->pluck('last_inbound_at', 'conversation_id');

        $lastInboundByConversation = [];
        foreach ($previousInboundAt as $conversationId => $lastInboundAt) {
            $lastInboundByConversation[(int) $conversationId] = Carbon::parse($lastInboundAt);
        }

        $templateBreakdown = [
            'marketing' => ['count' => 0, 'cost' => '0.0000', 'rate' => MessageTemplate::defaultBillingCostForCategory('MARKETING')],
            'utility' => ['count' => 0, 'cost' => '0.0000', 'rate' => MessageTemplate::defaultBillingCostForCategory('UTILITY')],
            'authentication' => ['count' => 0, 'cost' => '0.0000', 'rate' => MessageTemplate::defaultBillingCostForCategory('AUTHENTICATION')],
            'other' => ['count' => 0, 'cost' => '0.0000', 'rate' => '0.0000'],
        ];

        $conversationSessions = 0;
        $billableWindowRate = number_format((float) self::BILLABLE_WINDOW_RATE, 4, '.', '');
        $billableConversationCost = '0.0000';
        $templateCostTotal = '0.0000';

        foreach ($inboundMessages as $message) {
            $sentAt = Carbon::parse($message->sent_at);
            $conversationId = (int) $message->conversation_id;
            $lastInboundAt = $lastInboundByConversation[$conversationId] ?? null;

            if (!$lastInboundAt || $sentAt->greaterThanOrEqualTo($lastInboundAt->copy()->addHours(24))) {
                $conversationSessions++;
            }

            $lastInboundByConversation[$conversationId] = $sentAt;
        }

        $templateMessages = Message::query()
            ->with(['template:id,category,billing_cost'])
            ->where('tenant_id', $tenant->id)
            ->where('direction', 'outbound')
            ->where('status', '!=', 'failed')
            ->whereBetween('sent_at', [$periodStart, $periodEnd])
            ->where($excludeFailedTemplateMessages)
            ->orderBy('conversation_id')
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get([
                'id',
                'conversation_id',
                'template_id',
                'sent_at',
                'status',
                'error_message',
            ]);

        foreach ($templateMessages as $message) {
            $template = $message->template;
            if (!$template) {
                $templateBreakdown['other']['count']++;
                continue;
            }

            $category = strtoupper((string) $template->category);
            $rate = $template->billing_cost !== null
                ? number_format((float) $template->billing_cost, 4, '.', '')
                : MessageTemplate::defaultBillingCostForCategory($category);

            $bucket = match ($category) {
                'MARKETING' => 'marketing',
                'UTILITY' => 'utility',
                'AUTHENTICATION' => 'authentication',
                default => 'other',
            };

            $templateBreakdown[$bucket]['count']++;
            $templateBreakdown[$bucket]['rate'] = $rate;
            $templateBreakdown[$bucket]['cost'] = number_format(
                (float) $templateBreakdown[$bucket]['cost'] + (float) $rate,
                4,
                '.',
                ''
            );
            $templateCostTotal = number_format((float) $templateCostTotal + (float) $rate, 4, '.', '');
        }

        $freeTierRemaining = max(0, self::FREE_TIER_LIMIT - $conversationSessions);
        $billableConversations = max(0, $conversationSessions - self::FREE_TIER_LIMIT);
        $billableConversationCost = number_format($billableConversations * (float) $billableWindowRate, 4, '.', '');
        $totalEstimatedCost = number_format((float) $templateCostTotal + (float) $billableConversationCost, 4, '.', '');

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
        ];
    }
}
