<?php

namespace App\Livewire\Admin\Orders;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * One order, as the person on the phone needs it: what was bought, where it
 * goes, what was paid, and what has happened to it so far.
 */
class Show extends Component
{
    public Order $order;

    public string $status = '';
    public string $note = '';

    /** Cancelling can put the goods back; it is a decision, so it is asked. */
    public bool $restock = true;

    public function mount(Order $order): void
    {
        $this->order = $order->load(['items', 'events.user', 'user']);
        $this->status = $order->status;
    }

    public function render()
    {
        return view('livewire.admin.orders.show', [
            'statuses' => (new Index)->statuses(),
            'events'   => $this->order->events()->with('user')->latest()->get(),
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
                'type'    => 'status',
                'from'    => $from,
                'to'      => $this->status,
                'note'    => $this->note ?: null,
            ]);

            // the goods only come back when someone says they did
            if (in_array($this->status, ['cancelled', 'returned'], true) && $this->restock) {
                $this->restock();
            }
        });

        $this->note = '';
        $this->order->refresh()->load('events.user');

        $this->dispatch('toast', message: __('admin.saved'));
    }

    protected function restock(): void
    {
        foreach ($this->order->items as $item) {
            Product::whereKey($item->product_id)->increment('stock', (int) $item->qty);
        }

        $this->order->events()->create([
            'user_id' => auth()->id(),
            'type'    => 'note',
            'note'    => __('admin.restocked'),
        ]);
    }

    public function addNote(): void
    {
        if (trim($this->note) === '') {
            return;
        }

        $this->order->events()->create([
            'user_id' => auth()->id(),
            'type'    => 'note',
            'note'    => trim($this->note),
        ]);

        $this->note = '';
        $this->order->load('events.user');

        $this->dispatch('toast', message: __('admin.saved'));
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
            'type'    => 'payment',
            'note'    => __('admin.marked_paid'),
        ]);

        $this->order->refresh()->load('events.user');
        $this->dispatch('toast', message: __('admin.saved'));
    }
}
