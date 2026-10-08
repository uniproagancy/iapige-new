<?php

return [

    'title' => 'Invoice :number',
    'subject' => 'Invoice for order :number',
    'intro' => ':name, the payment details are below. Please put the order number in the transfer reference.',

    /* ------------------------------------------------------------ requisites */

    'pay_to' => 'Payment details',
    'beneficiary' => 'Beneficiary',
    'tax_id' => 'Tax ID',
    'bank' => 'Bank',
    'iban' => 'Account (IBAN)',
    'reference' => 'Reference',
    'amount' => 'Amount due',
    'reference_note' => 'Please include :number in the reference — that is what matches the payment to your order.',

    /* ------------------------------------------------------------ contents */

    'items' => 'Order contents',
    'item' => 'Item',
    'customer' => 'Customer',

    /* ------------------------------------------------------------ dates and actions */

    'due' => 'Please pay by :date',
    'due_short' => 'Valid until :date',
    'open' => 'Open the invoice',
    'print' => 'Print',
    'questions' => 'Questions? Just reply to this email.',

];
