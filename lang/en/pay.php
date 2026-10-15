<?php

/*
 | Paying an order after it was placed — the link an operator sends, and the
 | page they open themselves while the customer is on the telephone.
 */
return [
    'title' => 'Pay for order #:number',
    'lead' => 'Check the order and go on to the bank.',
    'go' => 'Go to payment',
    'going' => 'Redirecting…',

    'refused' => [
        'expired_title' => 'This link has expired',
        'expired' => 'Payment links do not last long. Call us and we will send a new one.',

        'paid_title' => 'This order is already paid',
        'paid' => 'The money for this order has arrived — there is nothing more to pay.',

        'closed_title' => 'This order is closed',
        'closed' => 'The order was cancelled or returned, so it can no longer be paid.',

        'offline_title' => 'Online payment is not available',
        'offline' => 'This order was not placed for online payment. Call us and we will sort it out.',
    ],
];
