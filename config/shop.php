<?php

return [

    'phone' => env('SHOP_PHONE', '032 212 10 28'),
    'email' => env('SHOP_EMAIL', 'info@iapi.ge'),
    'delivery_days' => (int) env('SHOP_DELIVERY_DAYS', 2),

    /*
     | The shop's own profiles, published as schema.org sameAs. Google uses them
     | to tie the site to the brand it already knows; a wrong one ties it to
     | somebody else, so an unset line is left out rather than guessed.
     */
    'social' => array_filter([
        env('SHOP_FACEBOOK'),
        env('SHOP_INSTAGRAM'),
        env('SHOP_YOUTUBE'),
        env('SHOP_LINKEDIN'),
    ]),

    /*
     | What a customer paying by transfer needs in front of them. Every line is
     | printed on the invoice, so an empty one is a payment that will not arrive.
     */
    'company' => [
        'name' => env('SHOP_COMPANY', 'შპს IAPI.GE'),
        'tax_id' => env('SHOP_TAX_ID'),
        'address' => env('SHOP_ADDRESS', 'თბილისი, ჭავჭავაძის 42'),
        'bank' => env('SHOP_BANK', 'საქართველოს ბანკი'),
        'swift' => env('SHOP_SWIFT', 'BAGAGE22'),
        'iban' => env('SHOP_IBAN'),
    ],

    // how long an unpaid transfer order is held before the goods are released
    'invoice_valid_days' => (int) env('SHOP_INVOICE_DAYS', 3),

    /*
     | Requests a minute one supplier may receive from the import.
     |
     | ImportProductJob has always declared RateLimited('import'), but the
     | limiter behind that name was never defined — and an undefined limiter is
     | a no-op, so the pace it promised never existed and every worker hit the
     | source as fast as it could answer. Sources behind Cloudflare stop
     | answering at all when treated that way.
     */
    'import_rate' => (int) env('IMPORT_RATE_PER_MINUTE', 60),

];
