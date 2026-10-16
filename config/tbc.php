<?php

return [

    'base_url' => env('TBC_BASE_URL', 'https://api.tbcbank.ge'),

    'installment' => [
        'client_id' => env('TBC_CLIENT_ID'),
        'client_secret' => env('TBC_CLIENT_SECRET'),
        'merchant_key' => env('TBC_MERCHANT_KEY'),
        'campaign_id' => env('TBC_CAMPAIGN_ID'),

        'handling_fee' => (float) env('TBC_INSTALLMENT_FEE', 0.05),
    ],

];
