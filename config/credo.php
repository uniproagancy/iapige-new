<?php

return [
    'merchant_id' => env('CREDO_MERCHANT_ID'),
    'widget_url' => env('CREDO_WIDGET_URL', 'https://ganvadeba.credo.ge/widget/'),
    'fee' => (float) env('CREDO_FEE', 0.10),
    'field' => env('CREDO_FIELD', 'credoinstallment'),
    'label' => env('CREDO_LABEL', 'Credo'),

    /* Credo's split product. Same widget, different shop. */
    'split' => [
        'merchant_id' => env('CREDO_SPLIT_MERCHANT_ID'),
        'widget_url' => env('CREDO_SPLIT_WIDGET_URL', env('CREDO_WIDGET_URL', 'https://ganvadeba.credo.ge/widget/')),
        'fee' => (float) env('CREDO_SPLIT_FEE', env('CREDO_FEE', 0.10)),
        'field' => env('CREDO_SPLIT_FIELD', 'credoinstallment'),
        'label' => env('CREDO_SPLIT_LABEL', 'Credo Split'),
    ],
];
