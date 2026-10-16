<?php

return [

    'url' => env('SMSOFFICE_URL', 'https://smsoffice.ge/api/v2/send/'),
    'api_key' => env('SMSOFFICE_KEY'),
    'sender' => env('SMSOFFICE_SENDER'),

    'enabled' => (bool) env('SMSOFFICE_ENABLED', false),

    'timeout' => (int) env('SMSOFFICE_TIMEOUT', 10),

    'redirect_to' => env('SMSOFFICE_REDIRECT_TO'),

];
