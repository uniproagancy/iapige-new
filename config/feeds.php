<?php

return [

    'facebook' => [
        'title' => env('FEED_TITLE', 'IAPI.GE'),
        'description' => env('FEED_DESCRIPTION', 'IAPI.GE product feed'),
        'currency' => 'GEL',
        'locale' => 'ka',

        'cache_ttl' => 86400,
        'path' => 'feeds/facebook.xml',

        'min_price' => (float) env('FEED_MIN_PRICE', 70),

        'exclude_categories' => [],

        'exclude_brands' => [],

        'instalment_from' => 150,
        'instalment_months' => (int) env('INSTALMENT_MONTHS', 24),
    ],

    'google' => [
        'title' => env('FEED_TITLE', 'IAPI.GE'),
        'description' => env('FEED_DESCRIPTION', 'IAPI.GE product feed'),
        'currency' => 'GEL',
        'locale' => 'ka',

        'cache_ttl' => 86400,
        'path' => 'feeds/google.xml',

        'min_price' => (float) env('FEED_MIN_PRICE', 70),

        'exclude_categories' => [],
        'exclude_brands' => [],

        'instalment_from' => 150,
        'instalment_months' => (int) env('INSTALMENT_MONTHS', 24),
    ],

];
