<?php

return [

    'phone' => env('SHOP_PHONE', '032 212 10 28'),
    'email' => env('SHOP_EMAIL', 'info@iapi.ge'),
    'delivery_days' => (int) env('SHOP_DELIVERY_DAYS', 2),

    'instalment_months' => (int) env('INSTALMENT_MONTHS', 24),

    'dial_scheme' => env('DIAL_SCHEME', 'tel'),

    'phone_country' => env('PHONE_COUNTRY', '995'),

    'social' => array_filter([
        env('SHOP_FACEBOOK'),
        env('SHOP_INSTAGRAM'),
        env('SHOP_YOUTUBE'),
        env('SHOP_LINKEDIN'),
    ]),

    'company' => [
        'name' => env('SHOP_COMPANY', 'შპს IAPI.GE'),
        'tax_id' => env('SHOP_TAX_ID'),
        'address' => env('SHOP_ADDRESS', 'თბილისი, ჭავჭავაძის 42'),
        'bank' => env('SHOP_BANK', 'საქართველოს ბანკი'),
        'swift' => env('SHOP_SWIFT', 'BAGAGE22'),
        'iban' => env('SHOP_IBAN'),
    ],

    'invoice_valid_days' => (int) env('SHOP_INVOICE_DAYS', 3),

    'import_rate' => (int) env('IMPORT_RATE_PER_MINUTE', 240),

    'import_deadline_hours' => (int) env('IMPORT_DEADLINE_HOURS', 24),

    'import_timeout' => (int) env('IMPORT_TIMEOUT', 300),

    'import_image_budget' => (int) env('IMPORT_IMAGE_BUDGET', 120),

    'import_request_timeout' => (int) env('IMPORT_REQUEST_TIMEOUT', 30),

    'import_requests_per_product' => 3,

    'require_image' => (bool) env('IMPORT_REQUIRE_IMAGE', false),

    'require_stock' => (bool) env('IMPORT_REQUIRE_STOCK', true),

    'publish_on_import' => (bool) env('IMPORT_PUBLISH', false),

];
