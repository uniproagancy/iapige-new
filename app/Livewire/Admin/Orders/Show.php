<?php

namespace App\Livewire\Admin\Orders;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\Facebook\Pixel;
use App\Services\Payments\PaymentManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

class Show extends Component
{
    public const COMPLETED = 'completed';

    public const LINK_DAYS = 3;

    public Order $order;

    public string $status = '';

    public string $note = '';

    public function mount(Order $order): void
    {

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

        });

        if ($this->status === self::COMPLETED) {
            $this->reportSale();
        }

        $this->note = '';
        $this->order->refresh()->load(['items.product.images', 'events.user']);

        $this->dispatch('toast', message: __('admin.saved'));
    }

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
