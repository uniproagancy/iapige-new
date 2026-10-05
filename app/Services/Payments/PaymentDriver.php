<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentTransaction;

/**
 * What every payment method must be able to do.
 *
 * The shop only ever asks two things: send the customer somewhere to pay, and
 * tell me afterwards whether they did. Everything else — tokens, signatures,
 * retries — belongs inside the driver.
 */
interface PaymentDriver
{
    /** Starts a payment and returns the transaction holding the redirect URL. */
    public function start(Order $order, array $options = []): PaymentTransaction;

    /**
     * Reads the bank's verdict for a transaction and updates it.
     * Returns true when the money is confirmed.
     */
    public function confirm(PaymentTransaction $transaction): bool;

    /** The bank's own callback, when it sends one. */
    public function handleCallback(array $payload): ?PaymentTransaction;
}
