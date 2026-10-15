<?php

return [

    /*
     | Facebook / Instagram product feed.
     |
     | Only products a shopper could actually buy belong here: a rejected item
     | costs nothing, but an advert pointing at an empty page costs money.
     */
    'facebook' => [
        'title' => env('FEED_TITLE', 'IAPI.GE'),
        'description' => env('FEED_DESCRIPTION', 'IAPI.GE product feed'),
        'currency' => 'GEL',
        'locale' => 'ka',

        // regenerated in the background; the route only ever serves the file
        'cache_ttl' => 86400,
        'path' => 'feeds/facebook.xml',

        // cheap accessories drain the budget without paying for themselves
        'min_price' => (float) env('FEED_MIN_PRICE', 70),

        // categories that should never be advertised (ids)
        'exclude_categories' => [],

        // brands we are not allowed to advertise (slugs)
        'exclude_brands' => [],

        // monthly instalment label, shown above this price
        'instalment_from' => 150,
        'instalment_months' => (int) env('INSTALMENT_MONTHS', 24),
    ],

    /*
     | Google Merchant Center.
     |
     | The same document as above with Google's own spellings, plus the two
     | things it insists on: something to identify an item by (a barcode, or a
     | brand and a part number) and a group id so a phone's colours compete as
     | one product rather than against each other.
     */
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
