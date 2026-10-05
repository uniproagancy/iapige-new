<?php

return [

    /*
     | Test: https://test-api.tbcbank.ge
     | Live: https://api.tbcbank.ge
     */
    'base_url' => env('TBC_BASE_URL', 'https://api.tbcbank.ge'),

    'installment' => [
        'client_id'     => env('TBC_CLIENT_ID'),
        'client_secret' => env('TBC_CLIENT_SECRET'),
        'merchant_key'  => env('TBC_MERCHANT_KEY'),
        'campaign_id'   => env('TBC_CAMPAIGN_ID'),

        /*
         | What the shop adds to cover the bank's interest — a commercial term,
         | so it lives here rather than in the driver.
         */
        'handling_fee' => (float) env('TBC_INSTALLMENT_FEE', 0.05),
    ],

];
