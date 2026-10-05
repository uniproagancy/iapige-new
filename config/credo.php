<?php

return [

    'merchant_id' => env('CREDO_MERCHANT_ID'),
    'widget_url'  => env('CREDO_WIDGET_URL', 'https://ganvadeba.credo.ge/widget/'),

    /*
     | What the shop adds to cover Credo's cost. Kept here because it is a
     | commercial term, not a technical one — it changes with the contract.
     */
    'fee' => (float) env('CREDO_FEE', 0.10),

];
