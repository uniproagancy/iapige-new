<?php

return [
    'enabled' => (bool) env('WARRANTY_ENABLED', false),
    'public_enabled' => (bool) env('WARRANTY_PUBLIC_ENABLED', false),
    'sms_enabled' => (bool) env('WARRANTY_SMS_ENABLED', false),
    'agent_enabled' => (bool) env('WARRANTY_AGENT_ENABLED', false),
    'disk' => 'local',
    'upload_max_kb' => 20480,
    'seller' => [
        'name' => 'შპს „უნიპრო“',
        'tax_id' => '416353635',
        'address' => 'თბილისი, შარტავას ქ. №3',
        'phone' => '0322 12 10 28',
        'email' => 'info@iapi.ge',
    ],
];
