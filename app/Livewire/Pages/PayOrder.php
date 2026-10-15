<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\SendsToBank;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentManager;
use Livewire\Component;

/**
 * Paying an order that was not paid for at checkout.
 *
 * Most of what the shop sells is taken over the telephone, and the operator
 * cannot take a card down the line. So the order is placed first and the
 * payment is handed over afterwards: either the customer is sent this link
 * and pays on their own phone, or the operator opens it and pays on the
 * customer's behalf while they are still on the call. Both are the same page,
 * because they are the same payment.
 *
 * The link is signed and it expires. An order number is four or five digits —
 * the view-order gate exists precisely because they can be guessed — and this
 * page spends money, so it cannot be reachable by trying numbers.
 */
class PayOrder extends Component
{
    use SendsToBank;

    public Order $order;

    /**
     * Why this page is not offering to take money.
     *
     * One of: expired, paid, closed, offline. Kept apart from a 404 on
     * purpose: a customer who clicks yesterday's link has done nothing wrong
     * and should be told what happened, not shown an error.
     */
    public ?string $refused = null;

    public function mount(string $number): void
    {
        $order = Order::where('number', $number)->with('items')->firstOrFail();

        $this->order = $order;
        $this->refused = $this->refusal($order);
    }

    protected function refusal(Order $order): ?string
    {
        if (! request()->hasValidSignature()) {
            return 'expired';
        }

        if ($order->is_paid) {
            return 'paid';
        }

        if (in_array($order->status, ['cancelled', 'returned'], true)) {
            return 'closed';
        }

        return $this->payable() ? null : 'offline';
    }

    /** The method the order was placed on, if a bank can still take it. */
    protected function payable(): ?PaymentMethod
    {
        $method = $this->method();

        return $method
            && $method->is_online
            && $method->driver
            && app(PaymentManager::class)->has($method->driver)
            ? $method
            : null;
    }

    protected function method(): ?PaymentMethod
    {
        return PaymentMethod::active()->where('code', $this->order->payment)->first();
    }

    /* ------------------------------------------------------------------ paying */

    public function pay()
    {
        /*
         * Checked again rather than trusted from mount().
         *
         * The page may have sat open while the operator marked the order paid
         * by hand, or cancelled it — and this is the request that spends the
         * money, so it is the one that has to be sure.
         */
        $this->order->refresh();
        $this->refused = $this->refusalNow();

        if ($this->refused) {
            return null;
        }

        return $this->sendToBank($this->order, $this->method());
    }

    /** The same refusals, minus the signature — Livewire signs its own requests. */
    protected function refusalNow(): ?string
    {
        if ($this->order->is_paid) {
            return 'paid';
        }

        if (in_array($this->order->status, ['cancelled', 'returned'], true)) {
            return 'closed';
        }

        return $this->payable() ? null : 'offline';
    }

    public function render()
    {
        return view('livewire.pages.pay-order', [
            'method' => $this->method(),
        ])->layout('layouts.app', [
            'title' => __('pay.title', ['number' => $this->order->number]),
            // names a person, their telephone number and a sum: never indexed
            'seo' => ['robots' => 'noindex, nofollow'],
        ]);
    }
}
