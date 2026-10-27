<?php

namespace App\Livewire\Admin\Orders;

use App\Jobs\SendSmsJob;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\Facebook\Pixel;
use App\Services\Payments\PaymentCheck;
use App\Services\Payments\PaymentManager;
use App\Support\Phone;
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
        $this->markSeen($order);

        $this->order = $order->load(['items.product.images', 'events.user', 'user']);
        $this->status = $this->order->status;
    }

    /* Opening a new order is the operator picking it up, so it stops being
       new the moment it is read rather than waiting to be touched. */
    protected function markSeen(Order $order): void
    {
        if ($order->status !== 'new') {
            return;
        }

        $order->update(['status' => 'viewed']);

        $order->events()->create([
            'user_id' => auth()->id(),
            'type' => 'status',
            'from' => 'new',
            'to' => 'viewed',
        ]);
    }

    public function render()
    {
        return view('livewire.admin.orders.show', [
            'statuses' => (new Index)->statuses(),
            'events' => $this->order->events()->with('user')->latest()->get(),
            'payLink' => $this->payLink(),
            'transaction' => $this->order->transactions()->latest('id')->first(),
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
            || $this->order->status === Order::CANCELLED) {
            return null;
        }

        return URL::temporarySignedRoute('order.pay', now()->addDays(self::LINK_DAYS), [
            'number' => $this->order->number,
        ]);
    }

    public function sendPayLink(): void
    {
        $link = $this->payLink();

        if (! $link) {
            $this->dispatch('toast', type: 'error', message: __('admin.pay_link_unavailable'));

            return;
        }

        if (Phone::dial($this->order->phone) === null) {
            $this->dispatch('toast', type: 'error', message: __('admin.pay_link_no_phone'));

            return;
        }

        SendSmsJob::dispatch($this->order->phone, __('sms.pay_link', [
            'number' => $this->order->number,
            'total' => money($this->order->total),
            'link' => $link,
        ]));

        $this->order->events()->create([
            'user_id' => auth()->id(),
            'type' => 'payment',
            'note' => __('admin.pay_link_sent', ['phone' => $this->order->phone]),
        ]);

        $this->order->refresh()->load(['items.product.images', 'events.user']);

        $this->dispatch('toast', message: __('admin.pay_link_sent_toast'));
    }

    /* The bank is the one that knows. The callback and the sweeper ask it on
       their own; this is the same question asked by hand, for when neither has
       come back yet and somebody is standing at the counter. */
    public function askBank(): void
    {
        $answer = app(PaymentCheck::class)->ask($this->order);

        $this->order->refresh()->load('events.user');

        $this->dispatch('toast', message: $answer['message'], type: $answer['type']);
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

        $this->reportSale();

        $this->order->refresh()->load('events.user');
        $this->dispatch('toast', message: __('admin.saved'));
    }
}
