<?php

return [

    /* ------------------------------------------------------------ layout */

    'questions' => 'Questions?',
    'automatic' => 'This email was sent automatically — you can reply to it.',

    /* ------------------------------------------------------------ order placed */

    'placed_subject' => 'Order :number received',
    'placed_title' => 'Thank you for your order!',
    'placed_text' => ':name, we have your order and it is being processed. The details are below.',
    'your_number' => 'Your order number',
    'what_next' => 'What happens next',
    'next_call' => 'An operator will call you to confirm the order',
    'next_pack' => 'We pack your order and hand it to the courier',
    'next_deliver' => 'Delivery within :days working days',
    'order_items' => 'Order contents',
    'track' => 'View your order',
    'view_order' => 'View the order',

    /*
     * Status changes.
     *
     * One subject, title and text per order status, because a customer reading
     * "your order has changed" learns nothing — the status itself is the news.
     */

    'status_subject_new' => 'Order :number received',
    'status_title_new' => 'Order received',
    'status_text_new' => ':name, order :number has been received. We will call you shortly to confirm it.',

    'status_subject_confirmed' => 'Order :number confirmed',
    'status_title_confirmed' => 'Order confirmed',
    'status_text_confirmed' => ':name, order :number is confirmed and has gone through to packing.',

    'status_subject_packed' => 'Order :number is packed',
    'status_title_packed' => 'Order packed',
    'status_text_packed' => ':name, order :number is packed and will be handed to the courier shortly.',

    'status_subject_shipped' => 'Order :number is on its way',
    'status_title_shipped' => 'Order on its way',
    'status_text_shipped' => ':name, order :number is with the courier. They will call you before arriving.',

    'status_subject_completed' => 'Order :number delivered',
    'status_title_completed' => 'Order delivered',
    'status_text_completed' => ':name, order :number has been delivered. Thank you for your trust!',

    'status_subject_cancelled' => 'Order :number cancelled',
    'status_title_cancelled' => 'Order cancelled',
    'status_text_cancelled' => ':name, order :number has been cancelled. Call us on :phone for the details.',

    'status_subject_returned' => 'Order :number returned',
    'status_title_returned' => 'Order returned',
    'status_text_returned' => ':name, order :number has been recorded as returned. Contact us on :phone about the refund.',

];
