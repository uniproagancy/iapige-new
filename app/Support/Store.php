<?php

namespace App\Support;

use App\Models\Slide;

class Store
{
    public static function img(string $seed, int $i = 0, int $w = 480, int $h = 360): string
    {
        return 'https://picsum.photos/seed/'.rawurlencode("{$seed}-{$i}")."/{$w}/{$h}";
    }

    protected static function localized(array $byLocale): mixed
    {
        return $byLocale[app()->getLocale()] ?? $byLocale['ka'];
    }

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

    public static function slides(): array
    {
        $own = Slide::live()
            ->withTranslation()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (Slide $slide) => $slide->isDrawable());

        if ($own->isNotEmpty()) {
            return $own->map(fn (Slide $slide) => [
                'dates' => $slide->dates,
                'title' => $slide->title,
                'sub' => $slide->sub,
                'cta' => $slide->cta,
                'url' => $slide->url,
                'image' => $slide->imageUrl(),
                'img' => null,
            ])->values()->all();
        }

        return self::placeholderSlides();
    }

    protected static function placeholderSlides(): array
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
            'dates' => $t[0], 'title' => $t[1], 'sub' => $t[2], 'cta' => $t[3],
            'url' => null, 'image' => null, 'img' => $img[$i],
        ], $text, array_keys($text));
    }

    public static function sectionBanners(): array
    {
        $b = self::localized([
            'ka' => [
                'laptops' => ['სასწავლო სეზონი', "სტუდენტებს −20%\nლეპტოპებზე", 'წარადგინე სტუდენტის ბარათი და მიიღე დამატებითი ფასდაკლება ნებისმიერ მოდელზე.', 'პირობების ნახვა'],
                'audio' => ['IAPI Sound Days', "ყურსასმენი + დინამიკი\nერთად −25%", 'აირჩიე ნებისმიერი ორი აუდიო პროდუქტი და ფასდაკლება ავტომატურად დაგერიცხება.', 'ნაკრების შედგენა'],
                'gaming' => ['Level Up', "გეიმინგ ნაკრები\n1 ₾-დან განვადებით", 'კონსოლი, პერიფერია და სავარძელი — ერთ შეკვეთაში, 18 თვემდე განვადებით.', 'ნაკრების ნახვა'],
                'phones' => [
                    ['Trade-in', "ჩააბარე ძველი\nტელეფონი", 'შეფასება 3 წუთში, თანხა პირდაპირ ახალ შეკვეთაზე.', 'შეფასების დაწყება →'],
                    ['დაზღვევა', "ეკრანის დაზღვევა\n1 წელი", 'შემთხვევითი დაზიანება დაფარულია, ფრანშიზის გარეშე.', 'დეტალები →'],
                ],
                'smart' => [
                    ['სტარტერ ნაკრები', "ჭკვიანი სახლი\n299 ₾-დან", '3 ნათურა, ჰაბი და მოძრაობის სენსორი ერთად.', 'ნაკრების ნახვა →'],
                    ['მონტაჟი', "უფასო დაყენება\nთბილისში", 'ჩვენი ტექნიკოსი დააყენებს და დააკონფიგურირებს.', 'ჯავშნის დადება →'],
                ],
            ],
            'en' => [
                'laptops' => ['Back to school', "Students get −20%\non laptops", 'Show your student card and get an extra discount on any model.', 'See the terms'],
                'audio' => ['IAPI Sound Days', "Headphones + speaker\n−25% together", 'Pick any two audio products and the discount applies automatically.', 'Build a set'],
                'gaming' => ['Level Up', "Gaming bundle\nfrom 1 ₾ a month", 'Console, peripherals and a chair in one order, up to 18 months to pay.', 'See the bundle'],
                'phones' => [
                    ['Trade-in', "Trade in your\nold phone", 'Valued in 3 minutes, credited straight to your new order.', 'Start the valuation →'],
                    ['Insurance', "Screen cover\nfor a year", 'Accidental damage covered, no excess.', 'Details →'],
                ],
                'smart' => [
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
                    [],
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
                    [],
                ],
            ],
        ]);
    }

    public static function info(): array
    {
        return [
            'contactCards' => [
                ['k' => __('info.cards.hotline'), 'v' => '0322 12 10 28', 'note' => __('info.cards.hotline_hours'), 'href' => 'tel:0322121028'],
                ['k' => __('info.cards.email'),   'v' => 'info@iapi.ge',   'note' => __('info.cards.email_note'),    'href' => 'mailto:info@iapi.ge'],
                ['k' => __('info.cards.service'), 'v' => '0322 12 10 28', 'note' => __('info.cards.service_hours'), 'href' => 'tel:0322121028'],
                ['k' => __('info.cards.social'),  'v' => '@IAPI.ge',       'note' => 'Messenger / IG', 'href' => '#'],
            ],
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
                'returns' => ['name' => 'დაბრუნება', 'title' => 'დაბრუნება და გაცვლა', 'updated' => '2 ნოემბერი, 2026',
                    'intro' => 'ონლაინ ნაყიდ ნივთზე უარის თქმა შეგიძლიათ 14 კალენდარული დღის განმავლობაში, მიზეზის ახსნის გარეშე. ქვემოთ აღწერილია, როგორ და რა პირობებით.',
                    'sections' => [
                        ['h' => 'ვადა', 'p' => '14 კალენდარული დღე აითვლება:', 'list' => [
                            'ნივთის მიღების დღიდან — როცა მას თქვენ ან თქვენ მიერ დასახელებული პირი იღებს (კურიერი არ ითვლება)',
                            'რამდენიმე ნაწილად მიწოდებული შეკვეთისას — ბოლო ნაწილის მიღების დღიდან',
                            'რეგულარული მიწოდებისას — პირველი მიწოდების დღიდან',
                            'მომსახურების შემთხვევაში — შეთანხმების დღიდან',
                        ]],
                        ['h' => 'როგორ დავაბრუნოთ', 'p' => 'ამ 14 დღის განმავლობაში შეგვატყობინეთ, რომ შეძენაზე უარს ამბობთ — დაგვირეკეთ ან მოგვწერეთ, მიუთითეთ შეკვეთის ნომერი და ნათლად თქვით, რომ ნივთს აბრუნებთ. ამ ვადაში შეტყობინების გარეშე მიზეზის ახსნის გარეშე დაბრუნების უფლება აღარ მოქმედებს.', 'list' => [
                            'ტელეფონი: 0322 12 10 28',
                            'ელ-ფოსტა: info@iapi.ge',
                        ]],
                        ['h' => 'ნივთის დაბრუნება', 'p' => 'შეტყობინებიდან არაუგვიანეს 7 კალენდარული დღისა ნივთი უნდა დაგვიბრუნოთ სრული კომპლექტაციით — შეფუთვით, აქსესუარებითა და თანდართული დოკუმენტებით. დაბრუნების პირდაპირ ხარჯს თქვენ ფარავთ.'],
                        ['h' => 'როცა დაბრუნებას ჩვენ ვაგვარებთ', 'p' => 'თუ ნივთი არ შეესაბამება საიტზე აღწერილს, ქარხნული წუნი აქვს ან ტრანსპორტირებისას დაზიანდა, დაბრუნებას ჩვენ ვაგვარებთ და ხარჯსაც ჩვენ ვფარავთ.'],
                        ['h' => 'თანხის დაბრუნება', 'p' => 'გადახდილ თანხას შეტყობინების მიღებიდან 14 კალენდარული დღის განმავლობაში დაგიბრუნებთ, იმავე საშუალებით, რომლითაც გადაიხადეთ, თუ სხვა გზაზე არ შევთანხმდით. თანხის დაბრუნება შეიძლება გადავდოთ, სანამ ნივთს არ მივიღებთ ან მისი გამოგზავნის დამადასტურებელ დოკუმენტს არ წარმოგვიდგენთ — რომელიც უფრო ადრე მოხდება.', 'list' => [
                            'ბრუნდება სტანდარტული მიწოდების ღირებულებაც; თუ უფრო ძვირი მიწოდება აირჩიეთ, სხვაობა არ ბრუნდება',
                            'განვადებით ნაყიდ ნივთზე უარის თქმა დაკავშირებულ განვადების ხელშეკრულებასაც წყვეტს',
                        ]],
                        ['h' => 'ნივთის მდგომარეობა', 'p' => 'ნივთის დათვალიერება და შემოწმება შეგიძლიათ ისე, როგორც მაღაზიაში. თუ მისი ღირებულება იმაზე მეტი გამოყენების გამო შემცირდა, რაც ნივთის ბუნებისა და მუშაობის გასაცნობად არის საჭირო, სხვაობა თქვენ გეკისრებათ — თუ დაბრუნების უფლების შესახებ სრული ინფორმაცია მოგაწოდეთ.'],
                        ['h' => 'რა არ ბრუნდება მიზეზის გარეშე', 'p' => 'ეს შეზღუდვები ეხება მხოლოდ მიზეზის ახსნის გარეშე დაბრუნებას; წუნიან ნივთზე გარანტია მოქმედებს.', 'list' => [
                            'თქვენი ინდივიდუალური შეკვეთით დამზადებული ნივთი',
                            'მალფუჭებადი ან მოკლე ვარგისობის ვადის მქონე საქონელი',
                            'ჰერმეტულად შეფუთული ნივთი, რომლის შეფუთვაც გაიხსნა და ჯანმრთელობის ან ჰიგიენის გამო ვეღარ დაბრუნდება',
                            'ნივთი, რომელიც მიწოდებისას განუყოფლად შეერწყა სხვა ნივთს',
                            'ნივთი, რომლის ფასი 30 ₾-ს არ აღემატება',
                        ]],
                        ['h' => 'ფორს-მაჟორი', 'p' => 'ვალდებულების შეუსრულებლობაზე არცერთი მხარე არ აგებს პასუხს, თუ ის გამოიწვია შეკვეთის შემდეგ დაწყებულმა სტიქიამ, ტექნოლოგიურმა კატასტროფამ, ხანძარმა, საომარმა მოქმედებამ ან სახელმწიფოს გადაწყვეტილებამ. ასეთ დროს ვადა ამ გარემოების ხანგრძლივობით გრძელდება. მხარე, რომელსაც ეს შეეხო, მეორეს დაუყოვნებლივ ატყობინებს და კომპეტენტური ორგანოს დოკუმენტით ადასტურებს; შეუტყობინებლობა ან დაგვიანებული შეტყობინება ამ საფუძვლით პასუხისმგებლობისგან გათავისუფლების უფლებას ართმევს.'],
                    ]],
                'installments' => ['name' => 'განვადება', 'title' => 'განვადების პირობები', 'updated' => '1 აგვისტო, 2026',
                    'intro' => 'განვადება საქართველოს ბანკის, თიბისისა და კრედო ბანკის პარტნიორობით.',
                    'sections' => [
                        ['h' => 'დამტკიცება', 'p' => 'განაცხადი ივსება ონლაინ, დამტკიცება 3 წუთში.'],
                    ]],
            ],
            'en' => [
                'delivery' => ['name' => 'Delivery', 'title' => 'Delivery terms', 'updated' => '12 August 2026',
                    'intro' => 'IAPI orders are delivered by courier across Georgia. The rate depends on the city and the order total.',
                    'sections' => [
                        ['h' => 'Timing', 'p' => 'Orders arrive within 2 days in Tbilisi and in 3—7 working days in the regions.', 'list' => ['Tbilisi — 24 hours', 'Rustavi, Mtskheta — 24—48 hours', 'Other cities — 2—3 days']],
                        ['h' => 'Rates', 'p' => 'Courier delivery starts at 10 ₾; every city has a free-delivery threshold.'],
                    ]],
                'returns' => ['name' => 'Returns', 'title' => 'Returns and exchanges', 'updated' => '2 November 2026',
                    'intro' => 'You can withdraw from an online purchase within 14 calendar days, without giving a reason. Here is how, and on what terms.',
                    'sections' => [
                        ['h' => 'Time limit', 'p' => 'The 14 calendar days run:', 'list' => [
                            'from the day the item is received — by you or by someone you name (the courier does not count)',
                            'for an order delivered in parts — from the day the last part is received',
                            'for regular deliveries — from the first delivery',
                            'for a service — from the day it is agreed',
                        ]],
                        ['h' => 'How to return', 'p' => 'Within those 14 days, tell us that you are withdrawing — call or write, give the order number and say clearly that you are returning the item. Without notice within that time, the right to return without a reason lapses.', 'list' => [
                            'Phone: 0322 12 10 28',
                            'Email: info@iapi.ge',
                        ]],
                        ['h' => 'Sending the item back', 'p' => 'Return the item no later than 7 calendar days after your notice, complete — with its packaging, accessories and documents. The direct cost of returning it is yours.'],
                        ['h' => 'When we arrange the return', 'p' => 'If the item does not match its description on the site, has a factory defect or was damaged in transit, we arrange the return and cover its cost.'],
                        ['h' => 'Refund', 'p' => 'We refund what you paid within 14 calendar days of your notice, by the same means you paid, unless we agree otherwise. We may hold the refund until we receive the item or you show proof of sending it, whichever comes first.', 'list' => [
                            'Standard delivery is refunded too; if you chose a more expensive delivery, the difference is not',
                            'Withdrawing from an item bought on instalments also ends the linked instalment agreement',
                        ]],
                        ['h' => 'Condition of the item', 'p' => 'You may examine and try the item as you would in a shop. If its value fell through more use than needed to establish its nature and working, the difference is yours — provided we gave you full information about the right to withdraw.'],
                        ['h' => 'What cannot be returned without a reason', 'p' => 'These limits apply only to returns without a reason; a faulty item is covered by the warranty.', 'list' => [
                            'an item made to your individual order',
                            'perishable goods or goods with a short shelf life',
                            'a sealed item whose seal was broken and which cannot be returned for health or hygiene reasons',
                            'an item that, once delivered, was inseparably combined with other goods',
                            'an item priced at 30 ₾ or less',
                        ]],
                        ['h' => 'Force majeure', 'p' => 'Neither party is liable for failing to meet an obligation caused by a natural or technological disaster, fire, war or a government decision that began after the order. Time limits are extended for as long as such an event lasts. The affected party notifies the other without delay and confirms it with a document from a competent authority; failing to notify, or notifying late, removes this ground for release from liability.'],
                    ]],
                'installments' => ['name' => 'Instalments', 'title' => 'Instalment terms', 'updated' => '1 August 2026',
                    'intro' => 'Instalments with Bank of Georgia, TBC and Credo Bank.',
                    'sections' => [
                        ['h' => 'Approval', 'p' => 'Apply online and get a decision within 3 minutes.'],
                    ]],
            ],
        ]) + __('privacy.documents');
    }
}
