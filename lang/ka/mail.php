<?php

return [

    /* ------------------------------------------------------------ layout */

    'questions'            => 'კითხვები?',
    'automatic'            => 'ეს წერილი ავტომატურად გაიგზავნა — პასუხის გაცემა შეგიძლია.',

    /* ------------------------------------------------------------ order placed */

    'placed_subject'       => 'შეკვეთა :number მიღებულია',
    'placed_title'         => 'მადლობა შეკვეთისთვის!',
    'placed_text'          => ':name, შეკვეთა მიღებულია და დამუშავებაში გადავიდა. დეტალები ქვემოთაა.',
    'your_number'          => 'შეკვეთის ნომერი',
    'what_next'            => 'რა ხდება შემდეგ',
    'next_call'            => 'ოპერატორი დაგირეკავს შეკვეთის დასადასტურებლად',
    'next_pack'            => 'შეკვეთას შევაფუთავთ და კურიერს გადავცემთ',
    'next_deliver'         => 'მიწოდება :days სამუშაო დღეში',
    'order_items'          => 'შეკვეთის შემადგენლობა',
    'track'                => 'შეკვეთის ნახვა',
    'view_order'           => 'შეკვეთის ნახვა',

    /*
     * Status changes.
     *
     * One subject, title and text per order status, because a customer reading
     * "your order has changed" learns nothing — the status itself is the news.
     */

    'status_subject_new'       => 'შეკვეთა :number მიღებულია',
    'status_title_new'         => 'შეკვეთა მიღებულია',
    'status_text_new'          => ':name, შეკვეთა :number მიღებულია. მალე დაგირეკავთ დასადასტურებლად.',

    'status_subject_confirmed' => 'შეკვეთა :number დადასტურებულია',
    'status_title_confirmed'   => 'შეკვეთა დადასტურდა',
    'status_text_confirmed'    => ':name, შეკვეთა :number დადასტურებულია და შეფუთვაზე გადავიდა.',

    'status_subject_packed'    => 'შეკვეთა :number შეფუთულია',
    'status_title_packed'      => 'შეკვეთა შეფუთულია',
    'status_text_packed'       => ':name, შეკვეთა :number შეფუთულია და უახლოეს დროში კურიერს გადაეცემა.',

    'status_subject_shipped'   => 'შეკვეთა :number გზაშია',
    'status_title_shipped'     => 'შეკვეთა გზაშია',
    'status_text_shipped'      => ':name, შეკვეთა :number კურიერს გადაეცა. კურიერი მოსვლამდე დაგირეკავს.',

    'status_subject_completed' => 'შეკვეთა :number ჩაბარებულია',
    'status_title_completed'   => 'შეკვეთა ჩაბარდა',
    'status_text_completed'    => ':name, შეკვეთა :number ჩაბარებულია. მადლობა ნდობისთვის!',

    'status_subject_cancelled' => 'შეკვეთა :number გაუქმებულია',
    'status_title_cancelled'   => 'შეკვეთა გაუქმდა',
    'status_text_cancelled'    => ':name, შეკვეთა :number გაუქმებულია. დეტალებისთვის დაგვირეკე :phone.',

    'status_subject_returned'  => 'შეკვეთა :number დაბრუნებულია',
    'status_title_returned'    => 'შეკვეთა დაბრუნდა',
    'status_text_returned'     => ':name, შეკვეთა :number დაბრუნებულად დაფიქსირდა. თანხის დაბრუნებაზე დაგვიკავშირდი :phone.',

];
