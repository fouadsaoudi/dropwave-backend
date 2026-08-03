<?php

return [
    'default' => [
        'meta_rate' => 0.0100, // Meta wholesale cost per minute
        'agent_rate' => 0.0150, // Retail price charged to tenant per minute
    ],
    '1' => [ // US/Canada
        'meta_rate' => 0.0050,
        'agent_rate' => 0.0080,
    ],
    '44' => [ // UK
        'meta_rate' => 0.0080,
        'agent_rate' => 0.0120,
    ],
    '971' => [ // UAE
        'meta_rate' => 0.0200,
        'agent_rate' => 0.0300,
    ],
];
