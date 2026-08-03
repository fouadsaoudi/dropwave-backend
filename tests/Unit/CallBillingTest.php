<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Call;

class CallBillingTest extends TestCase
{
    public function test_pulse_billing_calculation()
    {
        $call = new Call([
            'direction' => 'outbound',
            'duration_seconds' => 25, // 5 pulses (25/6 = 4.16 -> rounded up to 5)
            'rate_per_minute' => 0.0150,
        ]);

        $this->assertEquals(0.0075, $call->calculateCost()); // 5 pulses * (0.0150 / 10) = 0.0075
        
        $call2 = new Call([
            'direction' => 'outbound',
            'duration_seconds' => 4, // 1 pulse (4/6 = 0.66 -> rounded up to 1)
            'rate_per_minute' => 0.01138, // rounded to 0.0114 due to decimal:4 casting
        ]);

        // 1 pulse * (0.0114 / 10) = 0.00114
        $this->assertEquals(0.00114, $call2->calculateCost());
    }
}
