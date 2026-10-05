<?php

return [

    'token_url' => env('BOG_TOKEN_URL', 'https://oauth2.bog.ge/auth/realms/bog/protocol/openid-connect/token'),

    'installment' => [
        'public_key'  => env('BOG_INSTALLMENT_PUBLIC_KEY'),
        'secret_key'  => env('BOG_INSTALLMENT_SECRET_KEY'),
        'order_url'   => env('BOG_INSTALLMENT_ORDER_URL', 'https://installment.bog.ge/v1/installment/checkout'),
        'status_url'  => env('BOG_INSTALLMENT_STATUS_URL', 'https://installment.bog.ge/v1/installment/checkout'),
        'handling_fee' => (float) env('BOG_INSTALLMENT_FEE', 0.05),
        'months' => [3, 6, 12, 18, 24],
    ],
	
	'payment' => [
        'public_key'  => env('BOG_PAYMENT_PUBLIC_KEY'),
        'secret_key'  => env('BOG_PAYMENT_SECRET_KEY'),
        'order_url'   => env('BOG_PAYMENT_ORDER_URL', 'https://api.bog.ge/payments/v1/ecommerce/orders'),
        'receipt_url' => env('BOG_PAYMENT_RECEIPT_URL', 'https://api.bog.ge/payments/v1/receipt'),
		'callback_url' => env('BOG_CALLBACK_URL'),
    ],


];
