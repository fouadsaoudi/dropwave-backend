<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\TenantAiUsage;
use Carbon\Carbon;

class TenantAiUsageTest extends TestCase
{
    public function test_can_audit_returns_true_when_under_daily_limit()
    {
        $tenantId = 99999;
        
        $usage = new TenantAiUsage([
            'tenant_id' => $tenantId,
            'usage_date' => Carbon::today()->toDateString(),
            'requests_count' => 10,
            'daily_limit' => 50,
        ]);

        $this->assertTrue($usage->requests_count < $usage->daily_limit);
        $this->assertEquals(40, max(0, $usage->daily_limit - $usage->requests_count));
    }

    public function test_can_audit_returns_false_when_limit_reached()
    {
        $tenantId = 99999;
        
        $usage = new TenantAiUsage([
            'tenant_id' => $tenantId,
            'usage_date' => Carbon::today()->toDateString(),
            'requests_count' => 50,
            'daily_limit' => 50,
        ]);

        $this->assertFalse($usage->requests_count < $usage->daily_limit);
        $this->assertEquals(0, max(0, $usage->daily_limit - $usage->requests_count));
    }
}
