<?php

return [

    'supportedLocales' => [
        'ka' => ['name' => 'Georgian', 'script' => 'Geor', 'native' => 'ქართული', 'regional' => 'ka_GE', 'short' => 'ქარ'],
        'en' => ['name' => 'English',  'script' => 'Latn', 'native' => 'English',  'regional' => 'en_GB', 'short' => 'ENG'],
    ],

    'useAcceptLanguageHeader' => false,

    'hideDefaultLocaleInURL' => true,

    'localesOrder' => [],

    'localesMapping' => [],

    'utf8suffix' => env('LARAVELLOCALIZATION_UTF8SUFFIX', '.UTF-8'),

    'urlsIgnored' => ['/up'],

    'httpMethodsIgnored' => ['POST', 'PUT', 'PATCH', 'DELETE'],
];
