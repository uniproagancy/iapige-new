<?php

namespace App\Livewire\Admin\Orders;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Services\Facebook\Pixel;
use App\Services\Payments\PaymentManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

/**
 * One order, as the person on the phone needs it: what was bought, where it
 * goes, what was paid, and what has happened to it so far.
 */
class Show extends Component
{
    /** The status that means the shop has made the sale. */
    public const COMPLETED = 'completed';

    /**
     * How long a payment link stays good for.
     *
     * Long enough for a customer who says "send it, I will pay this evening",
     * short enough that a link forwarded on months later is not still live.
     */
    public const LINK_DAYS = 3;

    public Order $order;

    public string $status = '';

    public string $note = '';

    /** Cancelling can put the goods back; it is a decision, so it is asked. */
    public bool $restock = true;

    public function mount(Order $order): void
    {
        // the lines' own products come with them: one photograph each, not a query each
        $this->order = $order->load(['items.product.images', 'events.user', 'user']);
        $this->status = $order->status;
    }

    public function render()
    {
        return view('livewire.admin.orders.show', [
            'statuses' => (new Index)->statuses(),
            'events' => $this->order->events()->with('user')->latest()->get(),
            'payLink' => $this->payLink(),
        ])->layout('layouts.admin', [
            'title' => __('admin.order').' #'.$this->order->number,
        ]);
    }

    /* ------------------------------------------------------------------ status */

    public function changeStatus(): void
    {
        if ($this->status === $this->order->status) {
            return;
        }

        $from = $this->order->status;

        DB::transaction(function () use ($from) {
            $this->order->update(['status' => $this->status]);

            $this->order->events()->create([
                'user_id' => auth()->id(),
                'type' => 'status',
                'from' => $from,
                'to' => $this->status,
                'note' => $this->note ?: null,
            ]);

            // the goods only come back when someone says they did
            if (in_array($this->status, ['cancelled', 'returned'], true) && $this->restock) {
                $this->restock();
            }
        });

        /*
         * A completed order is a sale that happened, so Meta is told about it.
         *
         * Outside the transaction on purpose: it is an HTTP call to somebody
         * else's server, and a slow or broken one must not roll back a status
         * the operator has already been shown as saved.
         *
         * Reported once however often the status is walked back and forth —
         * the snapshot's sent_at is what remembers. An order the customer paid
         * for on the website was already reported by its bank callback, so
         * this finds it sent and does nothing.
         */
        if ($this->status === self::COMPLETED) {
            $this->reportSale();
        }

        $this->note = '';
        $this->order->refresh()->load(['items.product.images', 'events.user']);

        $this->dispatch('toast', message: __('admin.saved'));
    }

    /** The sale itself, told to Meta — never at the cost of the order. */
    protected function reportSale(): void
    {
        try {
            $sent = app(Pixel::class)->reportPurchase($this->order->fresh('items'), offline: true);
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        if ($sent) {
            $this->order->events()->create([
                'user_id' => auth()->id(),
                'type' => 'note',
                'note' => __('admin.purchase_reported'),
            ]);
        }
    }

    protected function restock(): void
    {
        foreach ($this->order->items as $item) {
            Product::whereKey($item->product_id)->increment('stock', (int) $item->qty);
        }

        $this->order->events()->create([
            'user_id' => auth()->id(),
            'type' => 'note',
            'note' => __('admin.restocked'),
        ]);
    }

    public function addNote(): void
    {
        if (trim($this->note) === '') {
            return;
        }

        $this->order->events()->create([
            'user_id' => auth()->id(),
            'type' => 'note',
            'note' => trim($this->note),
        ]);

        $this->note = '';
        $this->order->load('events.user');

        $this->dispatch('toast', message: __('admin.saved'));
    }

    /* ------------------------------------------------------------------ paying */

    /**
     * The link that takes this order to the bank, or null if it cannot.
     *
     * Signed and short-lived. An order number is four or five digits — the
     * view-order gate exists precisely because they can be guessed — and this
     * one spends money, so it must not be reachable by trying numbers.
     *
     * The same link serves both ways of using it: sent to the customer so
     * they pay on their own telephone, or opened by the operator while the
     * customer is still on the call. They are the same payment, so making
     * them two different pages would only mean two things to keep in step.
     */
    public function payLink(): ?string
    {
        $method = PaymentMethod::active()->where('code', $this->order->payment)->first();

        $payable = $method
            && $method->is_online
            && $method->driver
            && app(PaymentManager::class)->has($method->driver);

        if (! $payable || $this->order->is_paid
            || in_array($this->order->status, ['cancelled', 'returned'], true)) {
            return null;
        }

        return URL::temporarySignedRoute('order.pay', now()->addDays(self::LINK_DAYS), [
            'number' => $this->order->number,
        ]);
    }

    /** Marks a cash order as paid; online ones are marked by their callback. */
    public function markPaid(): void
    {
        if ($this->order->is_paid) {
            return;
        }

        $this->order->update(['is_paid' => true, 'paid_at' => now()]);

        $this->order->events()->create([
            'user_id' => auth()->id(),
            'type' => 'payment',
            'note' => __('admin.marked_paid'),
        ]);

        $this->order->refresh()->load('events.user');
        $this->dispatch('toast', message: __('admin.saved'));
    }
}
