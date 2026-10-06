<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'zoommer' => [
        'api_url' => env('ZOOMMER_API_URL', 'https://zoommer.ge/api/proxy/'),
        'site' => env('ZOOMMER_SITE', 'https://zoommer.ge/'),
        'user_agent' => env('ZOOMMER_USER_AGENT'),
        'sec_ch_ua' => env('ZOOMMER_SEC_CH_UA'),
        'access_token' => env('ZOOMMER_ACCESS_TOKEN'),
        // kept for compatibility; measured against the live API, this cookie is
        // not checked at all — only zoommer-access_token is
        'cf_clearance' => env('ZOOMMER_CF_CLEARANCE'),

        // set this to reach the source through the shop's worker, as the Elite
        // and Alta drivers do; a 403 is Cloudflare refusing a server address,
        // not a bad cookie, so no header can fix it from here
        'worker_url' => env('ZOOMMER_WORKER_URL'),
    ],
    'alta' => [
        'wsdl' => env('ALTA_WSDL', 'http://extra.alta.com.ge/b2b/b2bEWS?WSDL'),
        'user' => env('ALTA_B2B_USER'),
        'password' => env('ALTA_B2B_PASSWORD'),
        'worker_url' => env('ALTA_WORKER_URL'),
        'token' => env('ALTA_ACCESS_TOKEN'),
        'user_agent' => env('ALTA_USER_AGENT', 'Mozilla/5.0'),
    ],
    'elite' => [
        'worker_url' => env('ELITE_WORKER_URL'),
        'token' => env('ELITE_TOKEN'),
    ],
    'midea' => [
        'base_url' => env('MIDEA_BASE_URL', 'https://www.midea.ge'),
        'search_url' => env('MIDEA_SEARCH_URL'),
        'search_param' => env('MIDEA_SEARCH_PARAM', 'search'),
        'user_agent' => env('MIDEA_USER_AGENT', 'Mozilla/5.0'),
        'image_path' => env('MIDEA_IMAGE_PATH', '/storage/'),
        'spec_xpath' => [
            '//table//tr[td]',
            '//*[contains(@class,"spec")]//li',
            '//*[contains(@class,"characteristic")]//div[count(*)=2]',
        ],
        'description_xpath' => [
            '//*[contains(@class,"description")]',
            '//*[@id="description"]',
        ],
    ],
    'allmarket' => [
        'url' => env('ALLMARKET_URL', 'https://b2b.allmarket.ge/_mvcapi/productsapi/get'),
        'app_secret' => env('ALLMARKET_APP_SECRET'),
    ],
    'ingco' => [
        'user_agent' => env('INGCO_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
    ],
    'alneo' => [
        'shop_url' => env('ALNEO_SHOP_URL', 'https://alneo.ge/shop/?ppp=-1'),
        'user_agent' => env('ALNEO_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
    ],
    'kontakt' => [
        'user_agent' => env('KONTAKT_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
    ],
    'metromart' => [
        'user_agent' => env('METROMART_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
    ],

    /*
    | Meta (Facebook / Instagram) pixel and Conversions API.
    |
    | The same event is sent twice — once from the browser, once from here —
    | and Meta joins the pair by event_id. Both halves are needed: the browser
    | one carries the cookies, the server one survives ad blockers and iOS.
    |
    | content_ids must be the product's numeric id, because that is what
    | FacebookFeed writes as <g:id> — a mismatch means the catalogue never
    | connects to the pixel and dynamic ads have nothing to show.
    */
    'facebook' => [
        'pixel_id' => env('FACEBOOK_PIXEL_ID'),
        'access_token' => env('FACEBOOK_ACCESS_TOKEN'),
        'api_version' => env('FACEBOOK_API_VERSION', 'v24.0'),

        /*
        | While this is set every event lands in Test Events only and counts
        | nowhere else. Empty it in .env once the test run is done.
        */
        'test_event_code' => env('FACEBOOK_TEST_EVENT_CODE') ?: null,
    ],

];
