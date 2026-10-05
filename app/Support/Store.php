<?php

namespace App\Support;

/**
 * Content that doesn't have a table yet: hero slides, section banners, product
 * page extras (reviews, highlights), and the contact / about / document pages.
 * Products, categories and brands come from the database — see Catalog.
 *
 * Each block is keyed by locale and falls back to Georgian. These move to the
 * database with the content tables (slides, banners, pages, reviews).
 */
class Store
{
    public static function img(string $seed, int $i = 0, int $w = 480, int $h = 360): string
    {
        return 'https://picsum.photos/seed/'.rawurlencode("{$seed}-{$i}")."/{$w}/{$h}";
    }

    /** Pick the current locale's version, falling back to Georgian. */
    protected static function localized(array $byLocale): mixed
    {
        return $byLocale[app()->getLocale()] ?? $byLocale['ka'];
    }

    /* ------------------------------------------------------------------ footer */

    public static function pay(): array
    {
        return [
			['name' => 'VISA',       'logo' => 'visa.webp', 'color' => '#1A1F71', 'italic' => true],
            ['name' => 'Mastercard', 'logo' => 'mastercard.svg', 'color' => '#EB001B'],
        ];
    }

    public static function banks(): array
    {
        return self::localized([
            'ka' => [
                ['mark' => 'BOG', 'logo' => 'bog.png', 'color' => '#FF6900', 'name' => 'საქართველოს ბანკი'],
                ['mark' => 'TBC', 'logo' => 'tbc.png', 'color' => '#00A3E0', 'name' => 'თიბისი ბანკი'],
                ['mark' => 'CB',  'logo' => 'credo.png', 'color' => '#00843D', 'name' => 'კრედო ბანკი'],
            ],
            'en' => [
                ['mark' => 'BOG', 'logo' => 'bog.png', 'color' => '#FF6900', 'name' => 'Bank Of Georgia'],
                ['mark' => 'TBC', 'logo' => 'tbc.png', 'color' => '#00A3E0', 'name' => 'TBC Bank'],
                ['mark' => 'CB',  'logo' => 'credo.png', 'color' => '#00843D', 'name' => 'Credo Bank'],
            ],
        ]);
    }

    /* ------------------------------------------------------------------ home */

    public static function slides(): array
    {
        $img = [48, 180, 160, 1082, 1076];

        $text = self::localized([
            'ka' => [
                ['26—31 აგვისტო',   "ზაფხულის\nფინალური ამოყიდვა",   'NORDA-ს ტექნიკაზე',       'შეთავაზების ნახვა'],
                ['1—14 სექტემბერი', "სასწავლო სეზონი\nლეპტოპებზე −20%", 'სტუდენტებისთვის',         'აირჩიე ლეპტოპი'],
                ['ყოველდღე',       "განვადება 0%\n12 თვემდე",          'ონლაინ 3 წუთში',          'გაიგე მეტი'],
                ['ახალი',          "IAPI Studio One\nხმა სიჩუმეში",    'აქტიური ხმაურის ჩახშობა',  'შეიძინე 1 190 ₾'],
                ['შემოდგომა',      "ჭკვიანი სახლის\nსტარტერ ნაკრები",  'განათება & უსაფრთხოება',   'ნაკრების ნახვა'],
            ],
            'en' => [
                ['26—31 August',    "Summer\nclearance sale",          'On NORDA tech',            'See the deals'],
                ['1—14 September',  "Back to school:\nlaptops −20%",   'For students',             'Pick a laptop'],
                ['Every day',       "0% instalments\nup to 12 months", 'Approved online in 3 min', 'Learn more'],
                ['New',             "IAPI Studio One\nsound of silence", 'Active noise cancelling', 'Buy for 1 190 ₾'],
                ['Autumn',          "Smart home\nstarter kit",         'Lighting & security',      'See the kit'],
            ],
        ]);

        return array_map(fn ($t, $i) => [
            'dates' => $t[0], 'title' => $t[1], 'sub' => $t[2], 'cta' => $t[3], 'img' => $img[$i],
        ], $text, array_keys($text));
    }

    /** Banners under home sections, keyed by the section's default-language slug. */
    public static function sectionBanners(): array
    {
        $b = self::localized([
            'ka' => [
                'laptops' => ['სასწავლო სეზონი', "სტუდენტებს −20%\nლეპტოპებზე", 'წარადგინე სტუდენტის ბარათი და მიიღე დამატებითი ფასდაკლება ნებისმიერ მოდელზე.', 'პირობების ნახვა'],
                'audio'   => ['IAPI Sound Days', "ყურსასმენი + დინამიკი\nერთად −25%", 'აირჩიე ნებისმიერი ორი აუდიო პროდუქტი და ფასდაკლება ავტომატურად დაგერიცხება.', 'ნაკრების შედგენა'],
                'gaming'  => ['Level Up', "გეიმინგ ნაკრები\n1 ₾-დან განვადებით", 'კონსოლი, პერიფერია და სავარძელი — ერთ შეკვეთაში, 18 თვემდე განვადებით.', 'ნაკრების ნახვა'],
                'phones'  => [
                    ['Trade-in', "ჩააბარე ძველი\nტელეფონი", 'შეფასება 3 წუთში, თანხა პირდაპირ ახალ შეკვეთაზე.', 'შეფასების დაწყება →'],
                    ['დაზღვევა', "ეკრანის დაზღვევა\n1 წელი", 'შემთხვევითი დაზიანება დაფარულია, ფრანშიზის გარეშე.', 'დეტალები →'],
                ],
                'smart'   => [
                    ['სტარტერ ნაკრები', "ჭკვიანი სახლი\n299 ₾-დან", '3 ნათურა, ჰაბი და მოძრაობის სენსორი ერთად.', 'ნაკრების ნახვა →'],
                    ['მონტაჟი', "უფასო დაყენება\nთბილისში", 'ჩვენი ტექნიკოსი დააყენებს და დააკონფიგურირებს.', 'ჯავშნის დადება →'],
                ],
            ],
            'en' => [
                'laptops' => ['Back to school', "Students get −20%\non laptops", 'Show your student card and get an extra discount on any model.', 'See the terms'],
                'audio'   => ['IAPI Sound Days', "Headphones + speaker\n−25% together", 'Pick any two audio products and the discount applies automatically.', 'Build a set'],
                'gaming'  => ['Level Up', "Gaming bundle\nfrom 1 ₾ a month", 'Console, peripherals and a chair in one order, up to 18 months to pay.', 'See the bundle'],
                'phones'  => [
                    ['Trade-in', "Trade in your\nold phone", 'Valued in 3 minutes, credited straight to your new order.', 'Start the valuation →'],
                    ['Insurance', "Screen cover\nfor a year", 'Accidental damage covered, no excess.', 'Details →'],
                ],
                'smart'   => [
                    ['Starter kit', "Smart home\nfrom 299 ₾", 'Three bulbs, a hub and a motion sensor together.', 'See the kit →'],
                    ['Installation', "Free set-up\nin Tbilisi", 'Our technician installs and configures everything.', 'Book a visit →'],
                ],
            ],
        ]);

        $wide = fn (array $x, string $bg, string $img) => ['bg' => $bg, 'kicker' => $x[0], 'title' => $x[1], 'text' => $x[2], 'cta' => $x[3], 'img' => $img];
        $duo = fn (array $pair, array $imgs) => array_map(
            fn ($x, $img) => ['kicker' => $x[0], 'title' => $x[1], 'text' => $x[2], 'cta' => $x[3], 'img' => $img], $pair, $imgs);

        return [
            
        ];
    }

    /* ------------------------------------------------------------------ product page */

    /** Blocks of the product page that don't have tables yet. */
    public static function productExtras(): array
    {
        return [
            'bundle_skus' => ['ELO-D11', 'CSO-S14', 'KRF-GP'],
            'rating_bars' => [['5', 78, 99], ['4', 14, 18], ['3', 5, 7], ['2', 2, 3], ['1', 1, 1]],
        ] + self::localized([
            'ka' => [
                'highlights' => [['165Hz', 'QHD+ ეკრანი, 500 nit'], ['1.6 კგ', 'წონა ადაპტერის გარეშე'], ['11 სთ', 'ბატარეა ოფის-რეჟიმში'], ['24 თვე', 'ავტორიზებული გარანტია']],
                'reviews' => [
                    ['initial' => 'ნ', 'name' => 'ნინო ბერიძე', 'date' => '18 აგვისტო', 'score' => '5.0', 'text' => 'ორ კვირაა ვიყენებ მონტაჟისთვის — რენდერი შესამჩნევად აჩქარდა, ხმაური კი მინიმალურია. ეკრანის ფერები ქარხნიდანვე ზუსტია.'],
                    ['initial' => 'გ', 'name' => 'გიორგი ქავთარაძე', 'date' => '9 აგვისტო', 'score' => '4.5', 'text' => 'თამაშებში ბრწყინვალედ მიდის, ბატარეა კი დატვირთვისას სწრაფად იწურება. მიწოდება მეორე დღეს მოხდა.'],
                    ['initial' => 'ს', 'name' => 'სალომე თაბაგარი', 'date' => '2 აგვისტო', 'score' => '5.0', 'text' => 'კომპაქტური და მსუბუქია — 14 ინჩი ზუსტად ის ზომაა, რაც მჭირდებოდა. განვადება ონლაინ 5 წუთში დამიმტკიცეს.'],
                ],
                'services' => [
                    ['უფასო მიწოდება 24 საათში', 'თბილისში უფასოდ, რეგიონებში 2–3 დღეში კურიერით.'],
                    ['განვადება 0% — 12 თვემდე', 'ონლაინ დამტკიცება 3 წუთში, თავდაპირველი შენატანის გარეშე.'],
                    ['30 დღიანი დაბრუნება', 'შეკვეთა არ მოგერგო? დააბრუნე ყოველგვარი კითხვის გარეშე.'],
                ],
            ],
            'en' => [
                'highlights' => [['165Hz', 'QHD+ display, 500 nits'], ['1.6 kg', 'weight without the charger'], ['11 h', 'battery in office use'], ['24 months', 'authorised warranty']],
                'reviews' => [
                    ['initial' => 'N', 'name' => 'Nino Beridze', 'date' => '18 August', 'score' => '5.0', 'text' => 'Two weeks of video editing — renders are noticeably faster and it stays quiet. Colours are accurate out of the box.'],
                    ['initial' => 'G', 'name' => 'Giorgi Kavtaradze', 'date' => '9 August', 'score' => '4.5', 'text' => 'Runs games brilliantly, though the battery drains fast under load. Delivered the next day.'],
                    ['initial' => 'S', 'name' => 'Salome Tabagari', 'date' => '2 August', 'score' => '5.0', 'text' => 'Compact and light — 14 inches is exactly the size I wanted. Instalments were approved online in five minutes.'],
                ],
                'services' => [
                    ['Free delivery within 24 hours', 'Free in Tbilisi, 2–3 days by courier in the regions.'],
                    ['0% instalments up to 12 months', 'Approved online in 3 minutes, no down payment.'],
                    ['30-day returns', 'Not the right fit? Send it back, no questions asked.'],
                ],
            ],
        ]);
    }

    /* ------------------------------------------------------------------ contact / about / documents */

    public static function info(): array
    {
        return [
            'contactCards' => [
                ['k' => __('info.cards.hotline'), 'v' => '0322 12 10 28', 'note' => __('info.cards.hotline_hours'), 'href' => 'tel:0322121028'],
                ['k' => __('info.cards.email'),   'v' => 'info@iapi.ge',   'note' => __('info.cards.email_note'),    'href' => 'mailto:info@iapi.ge'],
                ['k' => __('info.cards.service'), 'v' => '0322 12 10 28', 'note' => __('info.cards.service_hours'), 'href' => 'tel:0322121028'],
                ['k' => __('info.cards.social'),  'v' => '@IAPI.ge',       'note' => 'Messenger / IG','href' => '#'],
            ],
            'topics' => [__('info.topics.order'), __('info.topics.delivery'), __('info.topics.warranty'), __('info.topics.installments')],
            'values' => self::localized([
                'ka' => [
                    ['n' => '01', 'title' => 'ხელით შერჩეული',  'text' => 'ასორტიმენტში მხოლოდ ის ტექნიკა შემოდის, რომელსაც ჩვენი გუნდი თავად ტესტავს.'],
                    ['n' => '02', 'title' => 'საკუთარი სერვისი', 'text' => 'ავტორიზებული სერვის-ცენტრი და სათადარიგო მოწყობილობა შეკეთების დროს.'],
                    ['n' => '03', 'title' => 'გამჭვირვალე ფასი', 'text' => 'ფასში შედის გარანტია და კონსულტაცია — ფარული საკომისიოები არ გვაქვს.'],
                ],
                'en' => [
                    ['n' => '01', 'title' => 'Hand-picked',        'text' => 'Only gear our own team has tested makes it onto the shelves.'],
                    ['n' => '02', 'title' => 'Our own service',    'text' => 'An authorised service centre, and a loan device while yours is repaired.'],
                    ['n' => '03', 'title' => 'Transparent prices', 'text' => 'Warranty and advice are included in the price — no hidden fees.'],
                ],
            ]),
            'docs' => self::docs(),
        ];
    }

    public static function docs(): array
    {
        return self::localized([
            'ka' => [
                'delivery' => ['name' => 'მიწოდება', 'title' => 'მიწოდების პირობები', 'updated' => '12 აგვისტო, 2026',
                    'intro' => 'IAPI-ს შეკვეთებს კურიერი მიგიტანთ საქართველოს მასშტაბით. ტარიფი დამოკიდებულია ქალაქზე და შეკვეთის ღირებულებაზე.',
                    'sections' => [
                        ['h' => 'ვადები', 'p' => 'თბილისში შეკვეთა მიწოდდება 2 სამუშაო დღის ვადაში, რეგიონში 3-7 სამუშაო დღეში.'],
                        ['h' => 'ტარიფი', 'p' => 'საკურიერო მომსახურება იწყება 5 ₾-დან; საკურიერო მომსახურების ღირებულება დამოკიდებულია ნივთის ზომაზე. რეგიონებში ხელმისაწვდომია მხოლოდ სტანდარტული მიწოდების სერვისი.'],
                    ]],
                'returns' => ['name' => 'დაბრუნება', 'title' => 'დაბრუნება და გაცვლა', 'updated' => '12 აგვისტო, 2026',
                    'intro' => 'ნებისმიერი პროდუქტი შეგიძლია დააბრუნო 30 დღის განმავლობაში მიზეზის მითითების გარეშე.',
                    'sections' => [
                        ['h' => 'პირობები', 'p' => 'პროდუქტი უნდა იყოს სრულ კომპლექტაციაში და დაზიანების გარეშე.', 'list' => ['30 დღე — დაბრუნება', '14 დღე — გაცვლა', 'თანხა 5 დღეში']],
                        ['h' => 'პროცესი', 'p' => 'შეავსე განაცხადი პროფილში — კურიერი ჩამოვა ნივთის ასაღებად უფასოდ.'],
                    ]],
                'warranty' => ['name' => 'გარანტია', 'title' => 'გარანტია და სერვისი', 'updated' => '5 აგვისტო, 2026',
                    'intro' => 'ყველა პროდუქტს აქვს ავტორიზებული გარანტია და ჩვენივე სერვის-ცენტრის მხარდაჭერა.',
                    'sections' => [
                        ['h' => 'ვადა', 'p' => 'სტანდარტული გარანტია 24 თვეა, აქსესუარებზე — 12 თვე.'],
                        ['h' => 'სათადარიგო', 'p' => 'თუ შეკეთება 5 დღეზე მეტს გრძელდება, უფასოდ გადმოგცემთ დროებით მოწყობილობას.'],
                    ]],
                'installments' => ['name' => 'განვადება', 'title' => 'განვადების პირობები', 'updated' => '1 აგვისტო, 2026',
                    'intro' => '0% განვადება 12 თვემდე საქართველოს ბანკის, თიბისისა და კრედო ბანკის პარტნიორობით.',
                    'sections' => [
                        ['h' => 'დამტკიცება', 'p' => 'განაცხადი ივსება ონლაინ, დამტკიცება 3 წუთში.'],
                        ['h' => 'ვადები', 'p' => 'ხელმისაწვდომია 3, 6, 12 და 18 თვიანი გრაფიკი.', 'list' => ['შენატანი 0 ₾-დან', 'მინიმუმი 300 ₾', 'ვადამდე დაფარვა უჯარიმოდ']],
                    ]],
            ],
            'en' => [
                'delivery' => ['name' => 'Delivery', 'title' => 'Delivery terms', 'updated' => '12 August 2026',
                    'intro' => 'IAPI orders are delivered by courier across Georgia. The rate depends on the city and the order total.',
                    'sections' => [
                        ['h' => 'Timing', 'p' => 'Orders arrive within 24 hours in Tbilisi and in 2—3 working days in the regions.', 'list' => ['Tbilisi — 24 hours', 'Rustavi, Mtskheta — 24—48 hours', 'Other cities — 2—3 days']],
                        ['h' => 'Rates', 'p' => 'Courier delivery starts at 10 ₾; every city has a free-delivery threshold.'],
                    ]],
                'returns' => ['name' => 'Returns', 'title' => 'Returns and exchanges', 'updated' => '12 August 2026',
                    'intro' => 'You can return any product within 30 days without giving a reason.',
                    'sections' => [
                        ['h' => 'Conditions', 'p' => 'The product must be complete and undamaged.', 'list' => ['30 days — return', '14 days — exchange', 'Refund within 5 days']],
                        ['h' => 'How it works', 'p' => 'Fill in a request in your profile — a courier collects the item for free.'],
                    ]],
                'warranty' => ['name' => 'Warranty', 'title' => 'Warranty and service', 'updated' => '5 August 2026',
                    'intro' => 'Every product carries an authorised warranty, backed by our own service centre.',
                    'sections' => [
                        ['h' => 'Term', 'p' => 'The standard warranty is 24 months; accessories get 12 months.'],
                        ['h' => 'Loan device', 'p' => 'If a repair takes longer than 5 days, you get a temporary device free of charge.'],
                    ]],
                'installments' => ['name' => 'Instalments', 'title' => 'Instalment terms', 'updated' => '1 August 2026',
                    'intro' => '0% instalments for up to 12 months with Bank of Georgia, TBC and Credo Bank.',
                    'sections' => [
                        ['h' => 'Approval', 'p' => 'Apply online and get a decision within 3 minutes.'],
                        ['h' => 'Terms', 'p' => '3, 6, 12 and 18-month schedules are available.', 'list' => ['Down payment from 0 ₾', 'Minimum 300 ₾', 'Early repayment without penalty']],
                    ]],
            ],
        ]);
    }
}
