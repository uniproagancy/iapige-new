<?php

return [
    'merchant_id' => env('CREDO_PART_MERCHANT_ID'),
    'widget_url' => env('CREDO_PART_WIDGET_URL', 'https://ganvadeba.credo.ge/widget/'),
    'fee' => (float) env('CREDO_PART_FEE', 0.10),
];
