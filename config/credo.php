<?php

return [
    'merchant_id' => env('CREDO_MERCHANT_ID'),
    'widget_url' => env('CREDO_WIDGET_URL', 'https://ganvadeba.credo.ge/widget/'),
    'fee' => (float) env('CREDO_FEE', 0.10),
];
