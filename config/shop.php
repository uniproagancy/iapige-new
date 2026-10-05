<?php

return [

    'phone'         => env('SHOP_PHONE', '032 212 10 28'),
    'email'         => env('SHOP_EMAIL', 'info@iapi.ge'),
    'delivery_days' => (int) env('SHOP_DELIVERY_DAYS', 2),

    /*
     | What a customer paying by transfer needs in front of them. Every line is
     | printed on the invoice, so an empty one is a payment that will not arrive.
     */
    'company' => [
        'name'    => env('SHOP_COMPANY', 'შპს IAPI.GE'),
        'tax_id'  => env('SHOP_TAX_ID'),
        'address' => env('SHOP_ADDRESS', 'თბილისი, ჭავჭავაძის 42'),
        'bank'    => env('SHOP_BANK', 'საქართველოს ბანკი'),
        'swift'   => env('SHOP_SWIFT', 'BAGAGE22'),
        'iban'    => env('SHOP_IBAN'),
    ],

    // how long an unpaid transfer order is held before the goods are released
    'invoice_valid_days' => (int) env('SHOP_INVOICE_DAYS', 3),

];
