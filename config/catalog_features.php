<?php

return [
    // Existing category IDs and descendants are read, never added or reassigned.
    // Semantic current filters qualify first. Configured explicitly named current-description properties may fill only missing claims; own model/SKU/brand cannot fill gaps.
    'profiles' => [
        'televisions' => [
            'categories' => [72],
            'kind' => 'screen_cm',
            'attributes' => [
                'ekranis-zoma', 'ეკრანის ზომა', 'ekranis-diagonali', 'ეკრანის დიაგონალი',
                'screen-diagonal', 'diagonal', 'დიაგონალი', 'zoma', 'screen-size', 'ზომა',
                'screen', 'ეკრანი',
            ],
        ],
        'phones' => [
            'categories' => [2],
            'kind' => 'storage',
            'attributes' => [
                'shida-mekhsiereba', 'შიდა მეხსიერება', 'internal-storage', 'built-in-storage',
                'ჩაშენებული მეხსიერება', 'storage-capacity', 'rom',
            ],
            'exclude' => ['operatiuli-mekhsiereba', 'ოპერატიული მეხსიერება', 'ram', 'ram-memory', 'memory-ram'],
        ],
        'climate_equipment' => [
            'categories' => [90],
            'kind' => 'area',
            'attributes' => [
                'rekomendirebuli-farti', 'რეკომენდირებული ფართი',
                'rekomendirebuli-fartobi', 'რეკომენდირებული ფართობი',
                'rekomendebuli-farti', 'რეკომენდებული ფართი',
                'fartobi', 'ფართობი', 'farti', 'ფართი',
                'gatbobagagrilebis-fartobi', 'გათბობა/გაგრილების ფართობი',
                'mokmedebis-fartobi', 'მოქმედების ფართობი',
                'gasatbobi-fartobi', 'გასათბობი ფართობი',
                'uncategorized-samushao-fartobi-m2', 'სამუშაო ფართობი (მ²)',
                'gatbobis-fartobi', 'გათბობის ფართობი',
                'recommended-area', 'room-area', 'coverage-area', 'cooling-area', 'heating-area',
            ],
        ],
        'refrigerators' => [
            'categories' => [83, 390],
            'kind' => 'volume',
            'attributes' => [
                'mtliani-motsuloba', 'მთლიანი მოცულობა', 'საერთო მოცულობა', 'ჯამური მოცულობა',
                'მთლიანი სასარგებლო მოცულობა', 'საერთო სასარგებლო მოცულობა',
                'საერთო ნეტო მოცულობა', 'მთლიანი ნეტო მოცულობა',
                'total-volume', 'total-capacity', 'total-net-capacity', 'total-net-volume', 'net-total-volume',
            ],
        ],
        'washing_machines' => [
            'categories' => [84],
            'kind' => 'load',
            'attributes' => [
                'maksimaluri-chatvirtva', 'მაქსიმალური ჩატვირთვა',
                'რეცხვის მოცულობა', 'რეცხვის ტევადობა', 'washing-capacity', 'wash-capacity',
                'ჩატვირთვის მოცულობა', 'load-capacity', 'maximum-load',
            ],
        ],
        'christmas_trees' => [
            'categories' => [410],
            'kind' => 'height',
            'attributes' => ['simaghle', 'სიმაღლე', 'height', 'tree-height', 'ნაძვის სიმაღლე'],
        ],
        // Additional families use only audited current attribute IDs. Existing profiles above stay unchanged.
        'laptop_ssd' => [
            'categories' => [7, 250], 'kind' => 'ssd_storage',
            'attribute_ids' => [10], 'attributes' => ['ssd-motsuloba'],
        ],
        'dryers' => [
            'categories' => [85], 'kind' => 'drying_load',
            'attribute_ids' => [756, 3805, 6737],
            'attributes' => ['maksimaluri-datvirtva', 'maksimaluri-chatvirtva', 'maksimaluri-chatvirtva-shroba'],
        ],
        'dishwashers' => [
            'categories' => [86], 'kind' => 'place_settings',
            'attribute_ids' => [3759, 4979, 5469],
            'attributes' => ['personis-raodenoba', 'personebis-raodenoba', 'uncategorized-nakrebis-tevadoba'],
        ],
        'ovens' => [
            'categories' => [94, 114], 'kind' => 'cavity_volume',
            'attribute_ids' => [265, 307, 3793],
            'attributes' => ['motsuloba', 'tevadoba', 'ghumelis-motsuloba'],
        ],
        'microwaves' => [
            'categories' => [97, 230], 'kind' => 'cavity_volume',
            'attribute_ids' => [128, 265, 3793],
            'attributes' => ['shida-motsuloba', 'motsuloba', 'ghumelis-motsuloba'],
        ],
        'extractor_hoods' => [
            'categories' => [88, 96], 'kind' => 'air_flow',
            'attribute_ids' => [2184, 5321, 5838],
            'attributes' => ['tsarmadoba', 'uncategorized-haeris-nakadis-machveneblebi-m3st', 'maksimaluri-produktiulobagatsova'],
            'unit_from_name_ids' => [5321],
        ],
        'hobs' => [
            'categories' => [95], 'kind' => 'cooking_zones',
            'attribute_ids' => [5525], 'attributes' => ['sakhurebeli-zonebis-raodenoba'],
        ],
        'electric_storage_water_heaters' => [
            'categories' => [391], 'kind' => 'volume',
            'attribute_ids' => [265, 3911],
            'attributes' => ['motsuloba', 'tsivi-tsqlis-rezervuaris-motsuloba'],
        ],
        'air_fryers' => [
            'categories' => [273], 'kind' => 'volume',
            'attribute_ids' => [265, 307, 1233, 3707],
            'attributes' => ['motsuloba', 'tevadoba', 'kalatis-motsuloba', 'mtliani-motsuloba'],
        ],
        'kettles' => [
            'categories' => [231], 'kind' => 'volume',
            'attribute_ids' => [239, 265, 307],
            'attributes' => ['tsqlis-avzis-motsuloba', 'motsuloba', 'tevadoba'],
        ],
        // Additional category rules are explicit, current-metadata-only and do not inherit.
        'tablets' => [
            'categories' => [9, 321],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'rom_storage',
            'attribute_ids' => [128, 189, 2103],
            'attributes' => ['shida-motsuloba', 'shida-mekhsiereba', 'flesh-mekhsiereba'],
            'caption' => [
                'ka' => 'შიდა მეხსიერება',
            ],
        ],
        'monitors' => [
            'categories' => [13, 253, 411],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'screen_inch',
            'attribute_ids' => [31, 1339, 5654],
            'attributes' => ['ekranis-zoma', 'ekranis-diagonali', 'screen'],
            'caption' => [
                'ka' => 'ეკრანის დიაგონალი',
            ],
        ],
        'desktop_ssd' => [
            'categories' => [11, 12],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'ssd_storage',
            'attribute_ids' => [10],
            'attributes' => ['ssd-motsuloba'],
            'caption' => [
                'ka' => 'SSD მოცულობა',
            ],
        ],
        'storage_media' => [
            'categories' => [33, 46, 47, 55],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'storage',
            'attribute_ids' => [189, 265, 1669],
            'attributes' => ['shida-mekhsiereba', 'motsuloba', 'mekhsierebis-tevadoba'],
            'caption' => [
                'ka' => 'მეხსიერების მოცულობა',
            ],
        ],
        'watch_diagonal' => [
            'categories' => [3, 325],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'screen_inch',
            'attribute_ids' => [31, 5654],
            'attributes' => ['ekranis-zoma', 'screen'],
            'caption' => [
                'ka' => 'ეკრანის დიაგონალი',
            ],
        ],
        'battery_capacity' => [
            'categories' => [59, 301],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'charge',
            'attribute_ids' => [59, 337, 409, 3213],
            'attributes' => ['elementis-motsuloba', 'akumulatoris-tevadoba', 'elementi', 'motsuloba-mah'],
            'caption' => [
                'ka' => 'ბატარეის ტევადობა',
            ],
        ],
        'charger_output' => [
            'categories' => [21, 56, 60, 64, 330],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'power',
            'attribute_ids' => [229, 317, 385, 943, 1336],
            'attributes' => ['simdzlavre', 'maksimaluri-simdzlavre', 'maksimaluri-saerto-simdzlavre', 'gamomavali-simdzlavre', 'saerto-simdzlavre'],
            'caption' => [
                'ka' => 'გამომავალი სიმძლავრე',
            ],
        ],
        'fitted_accessories' => [
            'categories' => [5, 10, 14, 37, 54, 57, 73, 75, 108, 236, 255, 258, 289, 317, 407],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'compatibility_model',
            'attribute_ids' => [194, 247, 1625, 2968, 3131],
            'attributes' => ['tavsebadoba', 'tavsebadi-motsqobilobebi', 'tavsebadi-motsqobiloba', 'damzadebulia-modelistvis', 'tsarmoebulia-modelistvis'],
            'caption' => [
                'ka' => 'თავსებადი მოდელი',
            ],
        ],
        'watch_straps' => [
            'categories' => [4],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'strap_size',
            'attribute_ids' => [3254],
            'attributes' => ['samajuris-tavsebadoba'],
            'caption' => [
                'ka' => 'სამაჯურის თავსებადობა',
            ],
        ],
        'laptop_accessory_fit' => [
            'categories' => [8],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'laptop_fit',
            'attribute_ids' => [194, 247, 1625, 2965, 6360, 7213],
            'attributes' => ['tavsebadoba', 'tavsebadi-motsqobilobebi', 'tavsebadi-motsqobiloba', 'chantis-zoma', 'leptopis-ekranis-maksimaluri-zoma', 'leptopis-maksimaluri-zoma'],
            'caption' => [
                'ka' => 'თავსებადობა / ლეპტოპის ზომა',
            ],
        ],
        'laptop_bags' => [
            'categories' => [20],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'compatible_screen',
            'attribute_ids' => [97, 2965, 3971],
            'attributes' => ['zoma', 'chantis-zoma', 'ekranis-zomastan-tavsebadoba'],
            'caption' => [
                'ka' => 'თავსებადი ეკრანის ზომა',
            ],
        ],
        'game_platform' => [
            'categories' => [347, 256, 675, 260, 254, 249],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'platform',
            'attribute_ids' => [2938, 2941],
            'attributes' => ['konsoltan-tavsebadoba', 'story-mode'],
            'caption' => [
                'ka' => 'თავსებადი პლატფორმა',
            ],
        ],
        'router_standard' => [
            'categories' => [24, 17],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'wifi_standard',
            'attribute_ids' => [87, 3219, 4147],
            'attributes' => ['wifi-standartebi', 'wi-fi-standarti', 'ukabelo-tsvdomis-standarti'],
            'caption' => [
                'ka' => 'Wi-Fi სტანდარტი',
            ],
            'requires_in_categories' => [
                17 => [
                    'attribute_id' => 157,
                    'code' => 'tipi',
                    'values' => ['Wi-Fi როუტერი', 'Access Point', 'Wi-Fi Router'],
                ],
            ],
        ],
        'mouse_sensitivity' => [
            'categories' => [40, 266],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'dpi',
            'attribute_ids' => [1129, 1300],
            'attributes' => ['dpi', 'dpi-mgrdznobeloba'],
            'caption' => [
                'ka' => 'DPI მგრძნობელობა',
            ],
        ],
        'keyboard_switch' => [
            'categories' => [42, 269],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'switch_type',
            'attribute_ids' => [575, 576, 582, 2624],
            'attributes' => ['gadamrtvelebi', 'gadamrtvelis-tipi', 'gadamrtveli', 'mekanikuri-gadamrtvelebis-tipi'],
            'caption' => [
                'ka' => 'გადამრთველის ტიპი',
            ],
        ],
        'print_technology' => [
            'categories' => [68],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'printing_technology',
            'attribute_ids' => [179, 467],
            'attributes' => ['teknologia', 'bechdvis-teknologia'],
            'caption' => [
                'ka' => 'ბეჭდვის ტექნოლოგია',
            ],
        ],
        'printer_compatibility' => [
            'categories' => [70],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'compatibility_model',
            'attribute_ids' => [194, 2348, 2790],
            'attributes' => ['tavsebadoba', 'printertan-tavsebadoba', 'tavsebadi-modelebi'],
            'caption' => [
                'ka' => 'თავსებადი პრინტერი',
            ],
        ],
        'projector_native_resolution' => [
            'categories' => [74],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'native_resolution',
            'attribute_ids' => [415, 1346, 1391, 2058, 2254],
            'attributes' => ['mshobliuri-garchevadoba', 'mshobliuri-rezolutsia', 'dziritadi-garchevadoba', 'mshobliuri-native-rezolutsia', 'naturaluri-garchevadoba'],
            'caption' => [
                'ka' => 'მშობლიური გარჩევადობა',
            ],
        ],
        'audio_output' => [
            'categories' => [43, 58, 77, 78, 349, 403],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'audio_power',
            'attribute_ids' => [168, 197, 229, 943, 1085, 3424, 6315],
            'attributes' => ['saerto-gamomavali-simdzlavre-rms', 'jamuri-simdzlavre-rms', 'simdzlavre', 'gamomavali-simdzlavre', 'saerto-gamomavali-simdzlavre', 'spikeris-simdzlavre', 'audio-gamomushavebuli-simdzlavre-vt'],
            'caption' => [
                'ka' => 'ხმის სიმძლავრე',
            ],
            'unit_from_name_ids' => [6315],
        ],
        'headphone_runtime' => [
            'categories' => [76, 313, 314, 315, 318, 346],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'runtime',
            'attribute_ids' => [145, 863, 5644],
            'attributes' => ['mushaobis-dro', 'elementis-khangrdzlivoba', 'elementis-mushaobis-khangrdzlivoba'],
            'caption' => [
                'ka' => 'მუშაობის ხანგრძლივობა',
            ],
        ],
        'wired_headphone_driver' => [
            'categories' => [316, 319],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'diameter',
            'attribute_ids' => [2518],
            'attributes' => ['dinamikis-diametri'],
            'caption' => [
                'ka' => 'დინამიკის დიამეტრი',
            ],
        ],
        'camera_photo_resolution' => [
            'categories' => [235],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'megapixels',
            'attribute_ids' => [3006],
            'attributes' => ['foto-rezolutsia'],
            'caption' => [
                'ka' => 'ფოტო რეზოლუცია',
            ],
        ],
        'action_video_resolution' => [
            'categories' => [238],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'video_resolution',
            'attribute_ids' => [3007],
            'attributes' => ['max-video-rezolutsia'],
            'caption' => [
                'ka' => 'მაქსიმალური ვიდეო რეზოლუცია',
            ],
        ],
        'security_video_resolution' => [
            'categories' => [303, 310, 344],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'video_resolution',
            'attribute_ids' => [588, 594, 1464, 3100, 6915],
            'attributes' => ['video-rezolutsia', 'tsina-kameris-rezolutsia', 'kameris-garchevadoba', 'maksimaluri-videos-rezolutsia', 'videos-chatseris-garchevadoba'],
            'caption' => [
                'ka' => 'ვიდეო რეზოლუცია',
            ],
        ],
        'drone_flight_time' => [
            'categories' => [239],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'flight_time',
            'attribute_ids' => [494, 624, 636],
            'attributes' => ['frenis-dro', 'maksimaluri-frenis-dro', 'frenis-maksimaluri-dro'],
            'caption' => [
                'ka' => 'მაქსიმალური ფრენის დრო',
            ],
        ],
        'electrical_appliance_tool_power' => [
            'categories' => [113, 115, 116, 117, 119, 120, 128, 129, 130, 232, 233, 275, 276, 277, 278, 109, 112, 123, 402, 131, 153, 154, 155, 156, 159, 160, 161, 163, 164, 165, 167, 168, 204, 205, 206, 669, 678],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'power',
            'attribute_ids' => [229, 233, 5241, 5606, 6139, 6185],
            'attributes' => ['simdzlavre', 'nominaluri-simdzlavre', 'energia-mokhmareba-simdzlavre-vt', 'energia-mokhmareba-simdzlavris-diapazoni-vt', 'dzala-simdzlavre', 'sheqvanis-nominaluri-simdzlavre'],
            'caption' => [
                'ka' => 'სიმძლავრე',
            ],
            'unit_from_name_ids' => [5241, 5606],
            'requires_in_categories' => [
                113 => [
                    'attribute_id' => 157,
                    'code' => 'tipi',
                    'values' => ['ჩოპერი', 'გრილი', 'Chopper', 'Grill'],
                ],
                131 => [
                    'attribute_id' => 157,
                    'code' => 'tipi',
                    'values' => ['დარტყმითი ბურღი', 'ელექტრო სალესი', 'აკუმულატორის დამტენი', 'Impact drill', 'Electric grinder', 'Battery charger'],
                ],
            ],
        ],
        'shaver_runtime' => [
            'categories' => [281, 285],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'runtime',
            'attribute_ids' => [145, 2065, 3839, 5272, 5572],
            'attributes' => ['mushaobis-dro', 'mushaobis-khangrdzlivoba', 'mushaobis-maksimaluri-khangrdzlivoba', 'battery-batareis-khangrdzlivoba-tst', 'battery-batareis-khangrdzlivoba-st'],
            'caption' => [
                'ka' => 'მუშაობის ხანგრძლივობა',
            ],
            'unit_from_name_ids' => [5272, 5572],
        ],
        'iron_steam_rate' => [
            'categories' => [292],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'steam_rate',
            'attribute_ids' => [3884, 5347],
            'attributes' => ['ortklis-mitsodeba', 'uncategorized-ortklis-gamomushaveba-gtst'],
            'caption' => [
                'ka' => 'ორთქლის მიწოდება',
            ],
            'unit_from_name_ids' => [5347],
        ],
        'coffee_pump_pressure' => [
            'categories' => [106, 107, 110],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'pressure',
            'attribute_ids' => [257, 724, 5255],
            'attributes' => ['tumbos-tsneva', 'tsneva', 'uncategorized-tumbos-tsneva-bari'],
            'caption' => [
                'ka' => 'ტუმბოს წნევა',
            ],
            'unit_from_name_ids' => [5255],
            'requires_in_categories' => [
                106 => [
                    'attribute_id' => 157,
                    'code' => 'tipi',
                    'values' => ['ყავის აპარატი', 'Coffee machine'],
                ],
            ],
        ],
        'vacuum_suction' => [
            'categories' => [288, 290, 212],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'suction',
            'attribute_ids' => [52, 235, 2234, 5243, 5568],
            'attributes' => ['shesrutvis-simdzlavre', 'shetsovis-simdzlavre', 'dzlieri-shetsovis-simdzlavre', 'mtversasrutis-makhasiateblebi-shetsovis-simdzlavre-vt', 'mtversasrutis-makhasiateblebi-shetsovis-simdzlavre-pa'],
            'caption' => [
                'ka' => 'შეწოვის სიმძლავრე',
            ],
            'unit_from_name_ids' => [5243, 5568],
        ],
        'cooker_cavity' => [
            'categories' => [89],
            'legacy_main_categories' => [87],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'cavity_volume',
            'attribute_ids' => [128, 3793, 6564],
            'attributes' => ['shida-motsuloba', 'ghumelis-motsuloba', 'gazkuris-motsuloba'],
            'caption' => [
                'ka' => 'ღუმელის მოცულობა',
            ],
            'requires_in_categories' => [
                89 => [
                    'attribute_id' => 157,
                    'code' => 'tipi',
                    'values' => ['ღუმელი', 'გაზქურა', 'Oven', 'Cooker'],
                ],
            ],
        ],
        'total_burners' => [
            'categories' => [87],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'burner_count',
            'attribute_ids' => [3722, 5018],
            'attributes' => ['komforebis-raodenoba', 'kanforebis-raodenoba'],
            'caption' => [
                'ka' => 'კონფორების რაოდენობა',
                'en' => 'Burner count',
            ],
        ],
        'multi_cooker_capacity' => [
            'categories' => [118],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'power',
            'attribute_ids' => [229],
            'attributes' => ['simdzlavre'],
            'caption' => [
                'ka' => 'სიმძლავრე',
            ],
            'unit_from_name_ids' => [],
        ],
        'cookware_capacity' => [
            'categories' => [393, 400],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'fluid_capacity',
            'attribute_ids' => [265],
            'attributes' => ['motsuloba'],
            'caption' => [
                'ka' => 'მოცულობა',
            ],
        ],
        'pan_diameter' => [
            'categories' => [394],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'cookware_diameter',
            'attribute_ids' => [929],
            'attributes' => ['diametri'],
            'caption' => [
                'ka' => 'დიამეტრი',
            ],
        ],
        'bottle_capacity' => [
            'categories' => [397, 398],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'fluid_capacity',
            'attribute_ids' => [307],
            'attributes' => ['tevadoba'],
            'caption' => [
                'ka' => 'მოცულობა',
            ],
        ],
        'water_pump_pressure' => [
            'categories' => [135],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'pressure',
            'attribute_ids' => [3532],
            'attributes' => ['samushao-tsneva'],
            'caption' => [
                'ka' => 'სამუშაო წნევა',
            ],
        ],
        'driver_torque' => [
            'categories' => [162, 213],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'torque',
            'attribute_ids' => [1514],
            'attributes' => ['maksimaluri-brunvis-momenti'],
            'caption' => [
                'ka' => 'მაქსიმალური ბრუნვის მომენტი',
            ],
        ],
        'generator_output' => [
            'categories' => [171],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'power',
            'attribute_ids' => [229],
            'attributes' => ['simdzlavre'],
            'caption' => [
                'ka' => 'გამომავალი სიმძლავრე',
            ],
        ],
        'welder_current' => [
            'categories' => [175, 177],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'current',
            'attribute_ids' => [6059],
            'attributes' => ['simdzlavris-diapazoni'],
            'caption' => [
                'ka' => 'შედუღების დენის დიაპაზონი',
            ],
        ],
        'transport_motor_power' => [
            'categories' => [297, 298, 299],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'power',
            'attribute_ids' => [229, 5241],
            'attributes' => ['simdzlavre', 'energia-mokhmareba-simdzlavre-vt'],
            'caption' => [
                'ka' => 'ძრავის სიმძლავრე',
            ],
            'unit_from_name_ids' => [5241],
        ],
        'children_vehicle_voltage' => [
            'categories' => [660],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'battery_voltage',
            'attribute_ids' => [234, 1263, 5974],
            'attributes' => ['akumulatoris-motsuloba', 'samushao-dzabva', 'akumulatori'],
            'caption' => [
                'ka' => 'ბატარეის ძაბვა',
            ],
        ],
        'cable_length' => [
            'categories' => [25, 48, 62, 81, 332],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'cable_length',
            'attribute_ids' => [278, 733],
            'attributes' => ['sigrdze', 'kabelis-sigrdze'],
            'caption' => [
                'ka' => 'კაბელის სიგრძე',
            ],
        ],
        'wall_mount_screen' => [
            'categories' => [80],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'compatible_screen',
            'attribute_ids' => [97, 1456, 1457, 1458, 2640],
            'attributes' => ['zoma', 'tavsebadi-televizoris-ekranis-zoma', 'maksimaluri-tavsebadi-zoma', 'tavsebadi-zoma', 'tavsebadi-ekranis-zoma'],
            'caption' => [
                'ka' => 'თავსებადი ეკრანის ზომა',
            ],
        ],
        'pc_supply_power' => [
            'categories' => [19, 29],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'power',
            'attribute_ids' => [229],
            'attributes' => ['simdzlavre'],
            'caption' => [
                'ka' => 'სიმძლავრე',
            ],
        ],
        'webcam_resolution' => [
            'categories' => [44],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'video_resolution',
            'attribute_ids' => [33],
            'attributes' => ['rezolutsia'],
            'caption' => [
                'ka' => 'ვიდეო რეზოლუცია',
            ],
        ],
        'microphone_pickup' => [
            'categories' => [268, 322],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'pickup_pattern',
            'attribute_ids' => [3226],
            'attributes' => ['khmis-mighebis-tipi'],
            'caption' => [
                'ka' => 'ხმის მიღების ტიპი',
            ],
        ],
        'air_compressor_tank' => [
            'categories' => [203],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'fluid_capacity',
            'attribute_ids' => [265, 1691],
            'attributes' => ['motsuloba', 'avzis-motsuloba'],
            'caption' => [
                'ka' => 'ავზის მოცულობა',
            ],
        ],
        'washer_pressure' => [
            'categories' => [137, 214, 291],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'pressure',
            'attribute_ids' => [724],
            'attributes' => ['tsneva'],
            'caption' => [
                'ka' => 'სამუშაო წნევა',
            ],
        ],
        'lift_capacity' => [
            'categories' => [211, 668],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'rated_load',
            'attribute_ids' => [756, 916, 6089],
            'attributes' => ['maksimaluri-datvirtva', 'maksimaluri-tsona', 'maks-tsona'],
            'caption' => [
                'ka' => 'დასაშვები დატვირთვა',
            ],
        ],
        'smart_switch_count' => [
            'categories' => [308, 345],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'switch_count',
            'attribute_ids' => [6583],
            'attributes' => ['chamrtvelebis-raodenoba'],
            'caption' => [
                'ka' => 'ჩამრთველების რაოდენობა',
            ],
        ],
        'cutlery_set_size' => [
            'categories' => [395, 396],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'piece_count',
            'attribute_ids' => [4085],
            'attributes' => ['nivtebis-raodenoba'],
            'caption' => [
                'ka' => 'ნაკრებში რაოდენობა',
            ],
        ],
        'coffee_package_mass' => [
            'categories' => [111],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'product_mass',
            'attribute_ids' => [30],
            'attributes' => ['tsona'],
            'caption' => [
                'ka' => 'შეფუთვის წონა',
            ],
        ],
        'fan_power' => [
            'categories' => [102],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'power',
            'attribute_ids' => [229],
            'attributes' => ['simdzlavre'],
            'caption' => [
                'ka' => 'სიმძლავრე',
            ],
        ],
        'lighting_power' => [
            'categories' => [306],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'power',
            'attribute_ids' => [229],
            'attributes' => ['simdzlavre'],
            'caption' => [
                'ka' => 'სიმძლავრე',
            ],
        ],
        'chair_user_capacity' => [
            'categories' => [263, 382],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'rated_load',
            'attribute_ids' => [756, 3272, 3336],
            'attributes' => ['maksimaluri-datvirtva', 'maksimaluri-simdzime', 'momkhmareblis-maksimaluri-tsona'],
            'caption' => [
                'ka' => 'მომხმარებლის მაქსიმალური წონა',
            ],
        ],
        'scale_capacity' => [
            'categories' => [125, 282],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'rated_load',
            'attribute_ids' => [756, 916],
            'attributes' => ['maksimaluri-datvirtva', 'maksimaluri-tsona'],
            'caption' => [
                'ka' => 'მაქსიმალური ასაწონი წონა',
            ],
        ],
        'dispenser_cold_tank' => [
            'categories' => [91],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'fluid_capacity',
            'attribute_ids' => [3911],
            'attributes' => ['tsivi-tsqlis-rezervuaris-motsuloba'],
            'caption' => [
                'ka' => 'ცივი წყლის ავზის მოცულობა',
            ],
        ],
        'furniture_dimensions' => [
            'categories' => [363, 367, 368, 369, 370, 372, 373, 374, 375, 376, 377, 378, 379, 380, 382, 383, 384, 388, 389],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'furniture_dimensions',
            'attribute_ids' => [],
            'attributes' => [],
            'caption' => [
                'ka' => 'პროდუქტის სრული ზომები',
            ],
        ],
        'lamp_height' => [
            'categories' => [364, 366, 677],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'height',
            'attribute_ids' => [],
            'attributes' => [],
            'caption' => [
                'ka' => 'სიმაღლე',
            ],
        ],
        'wood_router_power' => [
            'categories' => [157],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'power',
            'attribute_ids' => [],
            'attributes' => [],
            'caption' => [
                'ka' => 'სიმძლავრე',
            ],
        ],
        'machine_named_input_power' => [
            'categories' => [172],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'power',
            'attribute_ids' => [],
            'attributes' => [],
            'caption' => [
                'ka' => 'შემავალი სიმძლავრე',
            ],
        ],
        'disc_working_diameter' => [
            'categories' => [193],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'diameter',
            'attribute_ids' => [],
            'attributes' => [],
            'caption' => [
                'ka' => 'დისკის დიამეტრი',
            ],
        ],
        'drill_working_diameter' => [
            'categories' => [195],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'diameter',
            'attribute_ids' => [],
            'attributes' => [],
            'caption' => [
                'ka' => 'ბურღის დიამეტრი',
            ],
        ],
        'spray_nozzle' => [
            'categories' => [166],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'diameter',
            'attribute_ids' => [],
            'attributes' => [],
            'caption' => [
                'ka' => 'საქშენის ზომა',
            ],
        ],
        'garden_hose_length' => [
            'categories' => [147],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'cable_length',
            'attribute_ids' => [],
            'attributes' => [],
            'caption' => [
                'ka' => 'მილის სიგრძე',
            ],
        ],
        'children_recommended_age' => [
            'categories' => [664, 671, 672, 674],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'recommended_age',
            'attribute_ids' => [],
            'attributes' => [],
            'caption' => [
                'ka' => 'რეკომენდებული ასაკი',
            ],
        ],
        'press_force' => [
            'categories' => [667],
            'inherit' => false,
            'strict_codes' => true,
            'kind' => 'press_force',
            'attribute_ids' => [],
            'attributes' => [],
            'caption' => [
                'ka' => 'წნევის ძალა',
            ],
        ],
        // Gas water heaters406 have no proven L/min metadata; a bare12L cannot supply flow.
    ],
    'description_rules' => [
        'televisions' => [
            'headers' => ['ეკრანის დიაგონალი', 'ეკრანის ზომა', 'Screen diagonal', 'Screen size'],
        ],
        'phones' => [
            'headers' => ['შიდა მეხსიერება', 'ჩაშენებული მეხსიერება', 'Internal storage', 'Storage capacity'],
        ],
        'refrigerators' => [
            'headers' => ['მთლიანი მოცულობა', 'საერთო მოცულობა', 'ჯამური მოცულობა', 'Total volume', 'Total capacity'],
        ],
        'washing_machines' => [
            'headers' => ['მაქსიმალური ჩატვირთვა', 'რეცხვის მოცულობა', 'რეცხვის ტევადობა', 'Washing capacity'],
        ],
        'christmas_trees' => [
            'headers' => ['ნაძვის სიმაღლე', 'სიმაღლე', 'Tree height', 'Height'],
        ],
        'climate_equipment' => [
            'headers' => ['რეკომენდირებული ფართი', 'რეკომენდებული ფართი', 'გათბობის ფართობი', 'გასათბობი ფართობი', 'Recommended area', 'Heating area'],
        ],
        'charger_output' => [
            'headers' => ['მაქსიმალური გამომავალი სიმძლავრე', 'საერთო გამომავალი სიმძლავრე', 'გამომავალი სიმძლავრე', 'დატენვის სიმძლავრე', 'სიმძლავრე', 'Output power', 'Maximum output power'],
        ],
        'fitted_accessories' => [
            'headers' => ['თავსებადი მოდელები', 'დამზადებულია მოდელისთვის', 'თავსებადი მოწყობილობები', 'Compatible models'],
        ],
        'laptop_accessory_fit' => [
            'headers' => ['ლეპტოპის ეკრანის მაქსიმალური ზომა', 'ლეპტოპის მაქსიმალური ზომა', 'თავსებადი ლეპტოპის ზომა', 'თავსებადი მოდელები', 'Compatible laptop size', 'Compatible models'],
        ],
        'laptop_bags' => [
            'headers' => ['ჩანთის ზომა', 'თავსებადი ეკრანის ზომა', 'ეკრანის ზომასთან თავსებადობა', 'Compatible screen size'],
        ],
        'printer_compatibility' => [
            'headers' => ['თავსებადი მოდელები', 'დამზადებულია მოდელისთვის', 'თავსებადი მოწყობილობები', 'Compatible models'],
        ],
        'headphone_runtime' => [
            'headers' => ['მუშაობის ხანგრძლივობა', 'ელემენტის ხანგრძლივობა', 'მუშაობის დრო', 'Operating time', 'Battery life'],
        ],
        'wired_headphone_driver' => [
            'headers' => ['დიამეტრი', 'Diameter'],
        ],
        'electrical_appliance_tool_power' => [
            'headers' => ['შემავალი სიმძლავრე', 'ნომინალური სიმძლავრე', 'სიმძლავრე', 'Input power', 'Rated power', 'Power'],
        ],
        'shaver_runtime' => [
            'headers' => ['მუშაობის ხანგრძლივობა', 'ელემენტის ხანგრძლივობა', 'მუშაობის დრო', 'Operating time', 'Battery life'],
        ],
        'iron_steam_rate' => [
            'headers' => ['ორთქლის მიწოდება', 'ორთქლის გამომუშავება', 'Steam output'],
        ],
        'coffee_pump_pressure' => [
            'headers' => ['ტუმბოს წნევა', 'სამუშაო წნევა', 'Pump pressure', 'Working pressure'],
        ],
        'vacuum_suction' => [
            'headers' => ['შეწოვის სიმძლავრე', 'შესრუტვის სიმძლავრე', 'Suction power', 'Suction pressure'],
        ],
        'multi_cooker_capacity' => [
            'headers' => ['შემავალი სიმძლავრე', 'ნომინალური სიმძლავრე', 'სიმძლავრე', 'Input power', 'Rated power', 'Power'],
        ],
        'water_pump_pressure' => [
            'headers' => ['ტუმბოს წნევა', 'სამუშაო წნევა', 'Pump pressure', 'Working pressure'],
        ],
        'generator_output' => [
            'headers' => ['შემავალი სიმძლავრე', 'ნომინალური სიმძლავრე', 'სიმძლავრე', 'Input power', 'Rated power', 'Power'],
        ],
        'transport_motor_power' => [
            'headers' => ['შემავალი სიმძლავრე', 'ნომინალური სიმძლავრე', 'სიმძლავრე', 'Input power', 'Rated power', 'Power'],
        ],
        'children_vehicle_voltage' => [
            'headers' => ['აკუმულატორის ძაბვა', 'ბატარეის ძაბვა', 'აკუმულატორი', 'ბატარეა', 'Battery voltage', 'Battery'],
        ],
        'cable_length' => [
            'headers' => ['კაბელის სიგრძე', 'Cable length'],
        ],
        'wall_mount_screen' => [
            'headers' => ['ჩანთის ზომა', 'თავსებადი ეკრანის ზომა', 'ეკრანის ზომასთან თავსებადობა', 'Compatible screen size'],
        ],
        'pc_supply_power' => [
            'headers' => ['შემავალი სიმძლავრე', 'ნომინალური სიმძლავრე', 'სიმძლავრე', 'Input power', 'Rated power', 'Power'],
        ],
        'washer_pressure' => [
            'headers' => ['ტუმბოს წნევა', 'სამუშაო წნევა', 'Pump pressure', 'Working pressure'],
        ],
        'fan_power' => [
            'headers' => ['შემავალი სიმძლავრე', 'ნომინალური სიმძლავრე', 'სიმძლავრე', 'Input power', 'Rated power', 'Power'],
        ],
        'lighting_power' => [
            'headers' => ['შემავალი სიმძლავრე', 'ნომინალური სიმძლავრე', 'სიმძლავრე', 'Input power', 'Rated power', 'Power'],
        ],
        'furniture_dimensions' => [
            'headers' => [],
        ],
        'lamp_height' => [
            'headers' => ['სიმაღლე', 'Height'],
        ],
        'wood_router_power' => [
            'headers' => ['შემავალი სიმძლავრე', 'ნომინალური სიმძლავრე', 'სიმძლავრე', 'Input power', 'Rated power', 'Power'],
        ],
        'machine_named_input_power' => [
            'headers' => ['შემავალი სიმძლავრე', 'Input power'],
        ],
        'disc_working_diameter' => [
            'headers' => ['დისკის დიამეტრი', 'საჭრელი დისკის დიამეტრი', 'Disc diameter'],
        ],
        'drill_working_diameter' => [
            'headers' => ['ბურღის დიამეტრი', 'დიამეტრი', 'Drill diameter'],
        ],
        'spray_nozzle' => [
            'headers' => ['საქშენის ზომა', 'საქშენის დიამეტრი', 'საქშენი', 'Nozzle diameter', 'Nozzle size'],
        ],
        'garden_hose_length' => [
            'headers' => ['შლანგის სიგრძე', 'სიგრძე', 'Hose length', 'Length'],
        ],
        'children_recommended_age' => [
            'headers' => ['რეკომენდებული ასაკი', 'ასაკობრივი ჯგუფი', 'ასაკი', 'Recommended age', 'Age'],
        ],
        'press_force' => [
            'headers' => ['წნევის ძალა', 'Press force'],
        ],
    ],
];
