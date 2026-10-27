<?php

return [
    'merchant_id' => env('CREDO_MERCHANT_ID'),
    'widget_url' => env('CREDO_WIDGET_URL', 'https://ganvadeba.credo.ge/widget/'),
    'fee' => (float) env('CREDO_FEE', 0.10),
    'field' => env('CREDO_FIELD', 'credoinstallment'),
    'label' => env('CREDO_LABEL', 'Credo'),

    /* Asking Credo where an application stands. The hash is MD5 of the
       merchant id, the order code and this secret, run together.

       Credo issued a secret for the split product and none for this one, so
       this is ordinarily empty and these applications are settled by hand.
       Should Credo ever hand one over, filling it in is all it takes. */
    'status_url' => env('CREDO_STATUS_URL', 'https://ganvadeba.credo.ge/widget/api.php'),
    'secret' => env('CREDO_SECRET'),

    /* Credo's split product. Same widget, different shop. */
    'split' => [
        'merchant_id' => env('CREDO_SPLIT_MERCHANT_ID'),
        'widget_url' => env('CREDO_SPLIT_WIDGET_URL', env('CREDO_WIDGET_URL', 'https://ganvadeba.credo.ge/widget/')),
        'fee' => (float) env('CREDO_SPLIT_FEE', env('CREDO_FEE', 0.10)),
        'field' => env('CREDO_SPLIT_FIELD', 'credoinstallment'),
        'label' => env('CREDO_SPLIT_LABEL', 'Credo Split'),
        'status_url' => env('CREDO_SPLIT_STATUS_URL', env('CREDO_STATUS_URL', 'https://ganvadeba.credo.ge/widget/api.php')),

        /* Its own, and only its own: the other product has no secret to fall
           back on. */
        'secret' => env('CREDO_SPLIT_SECRET'),
    ],
];
