<?php

return [

    'questions' => 'Questions?',
    'automatic' => 'This email was sent automatically — you can reply to it.',

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

    'completed_subject' => 'Completed: :number — :total',
    'completed_title' => 'Order :number completed',
    'completed_text' => 'The order was marked completed in the admin. Details below.',
    'completed_customer' => 'Customer',
    'completed_phone' => 'Phone',
    'completed_email' => 'Email',
    'completed_delivery' => 'Delivery',
    'completed_address' => 'Address',
    'completed_payment' => 'Payment',
    'completed_paid' => 'Paid',
    'completed_paid_yes' => 'Yes',
    'completed_paid_no' => 'No',
    'completed_placed_at' => 'Placed',
    'completed_by' => 'Completed by',
    'completed_comment' => 'Comment',
    'completed_open' => 'Open in admin',
    'completed_log_sent' => 'Completion email sent: :to',
    'completed_log_failed' => 'Completion email failed (:to). Reason: :reason — :error',
    'reason_no_host' => 'MAIL_HOST is empty in the server .env',
    'reason_log_mailer' => 'MAIL_MAILER=log — mail is only written to the log, never sent',
    'reason_bad_host' => 'The MAIL_HOST address could not be found — check its spelling',
    'reason_auth' => 'The mail server refused the username or password — check MAIL_USERNAME and MAIL_PASSWORD (quote the password in .env)',
    'reason_port' => 'Could not connect to the server — check MAIL_PORT (465 or 587) or whether the host blocks it',
    'reason_tls' => 'Encryption error — use MAIL_SCHEME=smtps on 465, MAIL_SCHEME=null on 587',
    'reason_sender' => 'The server refused the sender — MAIL_FROM_ADDRESS should match MAIL_USERNAME',
    'reason_unknown' => 'Unknown reason — details are in the storage/logs/mail log',

];
