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
    'import_rate' => (int) env('IMPORT_RATE_PER_MINUTE', 240),

    /*
     | How long a queued product may keep waiting its turn, in hours.
     |
     | This has to outlast the whole run, not one job. At the rate above, a
     | thousand products take a quarter of an hour and ten thousand take most
     | of an afternoon — and the one at the back of the queue is being
     | postponed by the rate limiter that entire time. Laravel counts a
     | postponement as an attempt, so the deadline, not a count, is what
     | decides when to give up; anything that genuinely keeps throwing is
     | stopped by maxExceptions long before this.
     |
     | Raise it, or raise the rate, if a supplier's catalogue grows past what
     | this covers: at 60 a minute a day's deadline carries about 86,000.
     */
    'import_deadline_hours' => (int) env('IMPORT_DEADLINE_HOURS', 24),

    /*
     | How long one product may take, in seconds.
     |
     | A product is two API calls — one per language, up to thirty seconds
     | each — and then its photographs. The worker's default of sixty seconds
     | did not cover that, so a slow product was killed mid-way: no exception
     | recorded, only a spent attempt, and the queue re-ran it to be killed
     | again. Long enough for the slowest product, and the queue's retry_after
     | must stay longer still, or a job still running is handed to a second
     | worker as well.
     */
    'import_timeout' => (int) env('IMPORT_TIMEOUT', 300),

    /*
     | Of that, how long the photographs may take.
     |
     | Whatever is left when the budget runs out is fetched by the next run —
     | a file already on disk costs no request, so a gallery fills in over a
     | run or two instead of taking the job down on the first.
     */
    'import_image_budget' => (int) env('IMPORT_IMAGE_BUDGET', 120),

    /*
     | How long one request to a supplier may take, in seconds.
     |
     | Every driver used to write its own 30 here, which was fine until the
     | job's own limit was worked out against it — three numbers in three
     | files that nobody could compare. A supplier that needs longer still
     | overrides it with "timeout" in its own config row.
     */
    'import_request_timeout' => (int) env('IMPORT_REQUEST_TIMEOUT', 30),

    /*
     | The most requests a driver makes for one product.
     |
     | Zoommer asks once per language; the scrapers fetch a page and
     | sometimes a second one. It is here so the job's timeout can be checked
     | against the worst a product can cost, rather than against a guess.
     */
    'import_requests_per_product' => 3,

    /*
     | Whether a product with no photograph is worth importing.
     |
     | One that has none cannot be shown on a card, a listing or a search
     | result, so it is a hole in the catalogue rather than a product. With this
     | on, a newly imported product that ended up without one is taken straight
     | back out; products already in the catalogue are left alone, because one
     | may have had its picture added by hand.
     |
     | A supplier can override it with require_image in its own config — Alta's
     | B2B feed carries no pictures at all, so requiring them there would import
     | nothing.
     */
    'require_image' => (bool) env('IMPORT_REQUIRE_IMAGE', false),

    /*
     | Whether a product the supplier cannot hand over today is worth importing.
     |
     | A supplier's catalogue is far larger than its warehouse. Importing the
     | rest fills the shop with pages nobody can buy from, and each one still
     | costs photographs, a category to map by hand and a row to carry.
     |
     | Only new products are refused. One already in the catalogue goes out of
     | stock and stays, because "we are out of this" is not "this never
     | existed" — orders, hand-made edits and its history all point at it.
     |
     | On for every supplier. It was off, and off meant each one had to be
     | switched on by hand — so only Zoommer ever was, and the rest went on
     | importing a warehouse they did not have. A supplier that genuinely
     | wants the whole catalogue sets require_stock false in its own config.
     */
    'require_stock' => (bool) env('IMPORT_REQUIRE_STOCK', true),

    /*
     | Whether an imported product goes on sale by itself.
     |
     | Off, because most feeds arrive half-described: a bare model code for a
     | name, no category, a photograph that may or may not have downloaded.
     | Those are worth a look before a customer sees them.
     |
     | A supplier that answers with the title, the gallery and the description
     | is a different case, and turns this on with publish_on_import in its own
     | config. Either way readyToPublish still decides: a product with no
     | category or no name stays a draft, because publishing one puts a card on
     | the shop that cannot be browsed to.
     */
    'publish_on_import' => (bool) env('IMPORT_PUBLISH', false),

    /*
     | Seconds after which a single product is worth a line in the log.
     |
     | A run is thousands of products and logging each one would drown
     | the channel, but "the import is slow" cannot be answered without
     | knowing which half is slow — reaching the supplier, or writing
     | what came back. Only the ones that take long enough to wonder
     | about say so, with both halves timed.
     */
    'import_slow_product' => (float) env('IMPORT_SLOW_PRODUCT', 2),

];
