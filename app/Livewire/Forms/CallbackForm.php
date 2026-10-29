<?php

namespace App\Livewire\Forms;

use App\Livewire\Concerns\SendsToBank;
use App\Models\Order;
use App\Services\Facebook\Pixel;
use App\Services\Orders\QuickOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CallbackForm extends Component
{
    use SendsToBank;

    #[Locked]
    public ?int $productId = null;

    #[Locked]
    public string $submissionKey = '';

    #[Locked]
    public float $monthly = 0;

    #[Validate('integer|min:1|max:99')]
    public int $quantity = 1;

    public string $paymentCategory = '';

    public string $paymentMethod = '';

    #[Validate('required|string|min:2|max:80')]
    public string $name = '';

    #[Validate('required|string|min:9|max:32')]
    public string $phone = '';

    #[Validate('nullable|string|max:500')]
    public string $comment = '';

    #[Locked]
    public bool $sent = false;

    #[Locked]
    public ?string $orderNumber = null;

    #[Locked]
    public ?string $orderError = null;

    public function mount(?int $productId = null, int $quantity = 1, float $monthly = 0): void
    {
        $this->productId = $productId;
        $this->quantity = max(1, min(99, $quantity));
        $this->monthly = $monthly;
        $this->submissionKey = (string) Str::uuid();
        if ($user = Auth::user()) {
            $this->name = (string) $user->name;
            $this->phone = (string) ($user->phone ?? '');
        }
    }

    #[On('product-quantity-changed')]
    public function syncQuantity(int $productId, int $qty): void
    {
        if ($productId !== $this->productId) {
            return;
        }
        if (! $this->sent) {
            $this->quantity = max(1, min(99, $qty));
            $groups = app(QuickOrder::class)->groups($this->summary()['total']);
            $methods = $groups[$this->paymentCategory] ?? collect();
            if (! $methods->contains('code', $this->paymentMethod)) {
                $this->paymentMethod = '';
            }
            if ($this->paymentMethod === '' && in_array($this->paymentCategory, ['card', 'cash', 'transfer'], true) && $methods->count() === 1) {
                $this->paymentMethod = (string) $methods->first()->code;
            }
        }
        $this->dispatch('quick-order-quantity-synced', productId: $productId, qty: $this->quantity);
    }

    public function updatedPaymentCategory(): void
    {
        $this->paymentMethod = '';
        $this->resetErrorBag('paymentMethod');
        if (! in_array($this->paymentCategory, ['card', 'cash', 'transfer'], true) || ! $this->productId) {
            return;
        }
        $groups = app(QuickOrder::class)->groups($this->summary()['total']);
        $methods = $groups[$this->paymentCategory];
        if ($methods->count() === 1) {
            $this->paymentMethod = (string) $methods->first()->code;
        }
    }

    public function send()
    {
        $this->validate();

        /*
         | The shop takes orders here, and nothing else. A number left without
         | a way to pay used to become a call-back request for somebody to
         | ring; that is gone, so the form asks for the one thing it needs.
         */
        if ($this->paymentMethod === '' || ! $this->productId) {
            $this->addError('paymentMethod', __('quick_order.payment_needed'));

            return null;
        }

        $pixel = app(Pixel::class);
        $owner = $this->owner();

        $orders = app(QuickOrder::class);
        $lead = $orders->capture([
            'submissionKey' => $this->submissionKey, 'productId' => $this->productId,
            'quantity' => $this->quantity, 'name' => $this->name, 'phone' => $this->phone,
            'comment' => $this->comment, 'paymentCategory' => $this->paymentCategory,
            'paymentMethod' => $this->paymentMethod,
        ], $pixel->browserData() + [
            'owner' => $owner, 'user_id' => Auth::id(),
            'source_url' => mb_substr(url()->previous(), 0, 500), 'consented' => $pixel->consented(),
        ]);
        $this->track(fn () => $pixel->savedLead($lead));
        $this->sent = true;

        try {
            $order = $orders->orderForLead($lead->id);
        } catch (ValidationException $e) {
            $this->orderError = (string) collect($e->errors())->flatten()->first();

            return null;
        }
        $this->orderNumber = $order->number;
        $lead->refresh();
        $placed = (array) session('placed_orders', []);
        if (! in_array($order->id, $placed, true)) {
            session()->push('placed_orders', $order->id);
        }
        $this->track(fn () => $pixel->quickCheckout($lead, $order));
        if ($order->is_paid || $order->status === Order::COMPLETED) {
            return $this->redirect(route('order', $order->number), navigate: false);
        }
        $method = $orders->method((string) $lead->payment_method, (string) $lead->payment_category, (float) $order->total);
        if ($method->is_online) {
            return $this->sendToBank($order, $method);
        }
        $this->dispatch('toast', message: __('quick_order.order_placed'));

        return null;
    }

    protected function track(callable $capture): void
    {
        try {
            if ($event = $capture()) {
                $this->dispatch('pixel', $event);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function owner(): string
    {
        return hash_hmac('sha256', session()->getId(), (string) config('app.key'));
    }

    protected function summary(): ?array
    {
        if (! $this->productId) {
            return null;
        }
        $summary = app(QuickOrder::class)->quote($this->productId, $this->quantity);
        if ($this->monthly > 0) {
            $summary['monthly'] = $this->monthly;
        }

        return $summary;
    }

    public function again(): void
    {
        $this->sent = false;
        $this->orderNumber = null;
        $this->orderError = null;
        $this->submissionKey = (string) Str::uuid();
        $this->reset('paymentCategory', 'paymentMethod', 'comment');
        $this->resetErrorBag();
    }

    public function render()
    {
        $summary = $this->summary();

        return view('livewire.forms.callback-form', [
            'productSummary' => $summary,
            'paymentGroups' => $summary ? app(QuickOrder::class)->groups($summary['total']) : [],
        ]);
    }
}
