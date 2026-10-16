<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentTransaction;

interface PaymentDriver
{
    public function start(Order $order, array $options = []): PaymentTransaction;

    public function confirm(PaymentTransaction $transaction): bool;

    public function handleCallback(array $payload): ?PaymentTransaction;
}
