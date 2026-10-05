<?php

/*
 * mcamara/laravel-localization
 *
 * Languages are managed in the database (App\Models\Language). At boot,
 * App\Providers\AppServiceProvider replaces `supportedLocales` and
 * `localesOrder` below with the active rows of the `languages` table and
 * sets app.locale to the default language. The list here is only the
 * fallback used before the table is migrated and seeded.
 */
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
