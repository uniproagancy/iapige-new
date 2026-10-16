<?php

namespace App\Livewire\Admin\Orders;

use App\Models\CallbackRequest;
use App\Models\DeliveryCity;
use App\Models\Order;
use App\Models\OrderPixelData;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\Delivery\DeliveryCalculator;
use App\Services\Facebook\Pixel;
use App\Services\Orders\OrderLines;
use App\Support\Catalog;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

class Create extends Component
{
    public const STEPS = ['customer', 'items', 'delivery', 'payment'];

    public string $step = 'customer';

    public ?int $userId = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $customerSearch = '';

    public array $qty = [];

    public string $productSearch = '';

    public string $delivery = 'courier';

    public ?int $cityId = null;

    public string $address = '';

    public string $comment = '';

    public ?string $shippingOverride = null;

    public string $payment = '';

    #[Url(as: 'callback', except: null)]
    public ?int $callbackId = null;

    #[Url(as: 'product', except: null)]
    public ?int $productId = null;

    #[Url(as: 'user', except: null)]
    public ?int $forUser = null;

    public function mount(): void
    {
        if ($this->callbackId) {
            $this->fillFromCallback();
        }

        if ($this->forUser) {
            $this->useCustomer($this->forUser);
        }

        if ($this->productId && Product::whereKey($this->productId)->exists()) {
            $this->qty[$this->productId] = 1;
        }

        $this->cityId ??= DeliveryCity::active()->value('id');
        $this->payment = $this->payment ?: (string) (PaymentMethod::active()->value('code') ?? 'cash');
    }

    protected function fillFromCallback(): void
    {
        $request = CallbackRequest::with('product')->find($this->callbackId);

        if (! $request) {
            $this->callbackId = null;

            return;
        }

        if ($request->order_id) {
            $this->redirect(route('admin.orders.show', $request->order_id), navigate: true);

            return;
        }

        $this->name = (string) $request->name;
        $this->phone = (string) $request->phone;
        $this->comment = (string) $request->comment;
        $this->userId = $request->user_id;
        $this->email = (string) ($request->user?->email ?? '');

        if ($request->product_id) {
            $this->qty[$request->product_id] = 1;
        }
    }

    public function next(): void
    {
        $this->check($this->step);

        if ($this->step === 'items' && ! $this->lines()) {
            $this->addError('qty', __('admin.order_needs_an_item'));

            return;
        }

        $at = array_search($this->step, self::STEPS, true);

        $this->step = self::STEPS[min($at + 1, count(self::STEPS) - 1)];
    }

    public function back(): void
    {
        $at = array_search($this->step, self::STEPS, true);

        $this->step = self::STEPS[max($at - 1, 0)];
    }

    public function goTo(string $step): void
    {
        $at = array_search($this->step, self::STEPS, true);
        $to = array_search($step, self::STEPS, true);

        if ($to !== false && $to < $at) {
            $this->step = $step;
        }
    }

    protected function check(string $step): void
    {
        $rules = $this->rulesFor($step);

        if ($rules) {
            $this->validate($rules);
        }
    }

    protected function rulesFor(string $step): array
    {
        return match ($step) {
            'customer' => [
                'name' => ['required', 'string', 'min:2', 'max:80'],
                'phone' => ['required', 'string', 'min:9', 'max:32'],
                'email' => ['nullable', 'email', 'max:120'],
            ],
            'delivery' => array_merge([
                'delivery' => ['required', 'in:courier,pickup'],
                'comment' => ['nullable', 'string', 'max:1000'],
                'shippingOverride' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            ], $this->delivery === 'courier' ? [
                'cityId' => ['required', 'exists:delivery_cities,id'],
                'address' => ['required', 'string', 'min:5', 'max:160'],
            ] : []),

            'payment' => ['payment' => ['required', 'string', 'exists:payment_methods,code']],
            default => [],
        };
    }

    public function customers()
    {
        $term = trim($this->customerSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $digits = preg_replace('/\D/', '', $term);

        return User::query()
            ->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->when($digits !== '', fn ($p) => $p->orWhere('phone', 'like', "%{$digits}%")))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'phone', 'email']);
    }

    public function useCustomer(int $id): void
    {
        $user = User::find($id);

        if (! $user) {
            return;
        }

        $this->userId = $user->id;
        $this->name = (string) $user->name;
        $this->phone = (string) ($user->phone ?? $this->phone);
        $this->email = (string) $user->email;
        $this->customerSearch = '';

        $address = UserAddress::where('user_id', $user->id)->orderByDesc('is_default')->first();

        if ($address) {
            $this->cityId = $address->city_id;
            $this->address = (string) $address->address;
        }
    }

    public function forgetCustomer(): void
    {
        $this->userId = null;
    }

    public function found()
    {
        $term = trim($this->productSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        return Catalog::productQuery()
            ->where(fn ($q) => $q
                ->whereTranslation('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%"))
            ->limit(12)
            ->get();
    }

    public function add(int $id): void
    {

        $sellable = Product::whereKey($id)
            ->where('status', Product::STATUS_ACTIVE)
            ->where('is_preorder', false)
            ->exists();

        if (! $sellable) {
            $this->dispatch('toast', type: 'error', message: __('admin.product_not_sellable'));

            return;
        }

        $this->qty[$id] = ($this->qty[$id] ?? 0) + 1;
        $this->productSearch = '';
        $this->resetErrorBag('qty');
    }

    public function increment(int $id): void
    {
        if (isset($this->qty[$id])) {
            $this->qty[$id] = min(99, $this->qty[$id] + 1);
        }
    }

    public function decrement(int $id): void
    {
        if (! isset($this->qty[$id])) {
            return;
        }

        $this->qty[$id] > 1 ? $this->qty[$id]-- : $this->remove($id);
    }

    public function remove(int $id): void
    {
        unset($this->qty[$id]);
    }

    public function lines(): array
    {
        if (! $this->qty) {
            return [];
        }

        $products = Product::whereIn('id', array_keys($this->qty))
            ->with('brand')
            ->withTranslation()
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($this->qty as $id => $qty) {
            $product = $products[$id] ?? null;

            if (! $product) {
                continue;
            }

            $price = $product->sellingPrice();

            $lines[] = [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => trim(($product->brand?->name ?? '').' '.$product->name),
                'price' => $price,
                'qty' => $qty,
                'sum' => $price * $qty,
                'stock' => (int) $product->stock,
            ];
        }

        return $lines;
    }

    public function subtotal(): float
    {
        return (float) array_sum(array_column($this->lines(), 'sum'));
    }

    public function shipping(): float
    {
        if ($this->shippingOverride !== null && $this->shippingOverride !== '') {
            return round((float) $this->shippingOverride, 2);
        }

        if ($this->delivery === 'pickup') {
            return 0.0;
        }

        $city = $this->cityId ? DeliveryCity::active()->find($this->cityId) : null;

        if (! $city) {
            return 0.0;
        }

        $items = array_map(
            fn (array $line) => ['product_id' => $line['id'], 'qty' => $line['qty']],
            $this->lines(),
        );

        return app(DeliveryCalculator::class)->fee($city, app(OrderLines::class)->measured($items));
    }

    public function total(): float
    {
        return $this->subtotal() + $this->shipping();
    }

    protected function method(): ?PaymentMethod
    {
        return PaymentMethod::active()->where('code', $this->payment)->first();
    }

    public function place()
    {

        foreach (self::STEPS as $step) {
            $this->check($step);
        }

        $wanted = array_map(
            fn (array $line) => ['id' => $line['id'], 'qty' => $line['qty']],
            $this->lines(),
        );

        if (! $wanted) {
            $this->step = 'items';
            $this->addError('qty', __('admin.order_needs_an_item'));

            return null;
        }

        if (! $this->method()) {
            $this->step = 'payment';
            $this->addError('payment', __('checkout.payment_unavailable'));

            return null;
        }

        try {
            $order = DB::transaction(fn () => $this->write($wanted));
        } catch (RuntimeException $e) {
            $this->step = 'items';
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return null;
        }

        session()->flash('toast', __('admin.order_placed', ['number' => $order->number]));

        return $this->redirect(route('admin.orders.show', $order), navigate: true);
    }

    protected function write(array $wanted): Order
    {
        $lines = app(OrderLines::class);
        $priced = $lines->price($wanted);

        $city = $this->delivery === 'courier' && $this->cityId
            ? DeliveryCity::withTranslation()->find($this->cityId)
            : null;

        $shipping = $this->shipping();

        $order = Order::create([
            'number' => Order::nextNumber(),
            'user_id' => $this->userId,
            'status' => 'new',
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email ?: null,
            'delivery' => $this->delivery,
            'delivery_city_id' => $city?->id,
            'city' => $city?->name,
            'address' => $this->delivery === 'courier' ? $this->address : null,
            'comment' => $this->comment ?: null,
            'payment' => $this->payment,

            'payment_status' => 'pending',
            'is_paid' => false,
            'subtotal' => $priced['subtotal'],
            'shipping' => $shipping,
            'total' => $priced['subtotal'] + $shipping,
        ]);

        $lines->attach($order, $priced['items']);

        $order->events()->create([
            'user_id' => auth()->id(),
            'type' => 'status',
            'to' => 'new',
            'note' => __('order.placed_by_operator', ['name' => auth()->user()?->name ?? '—']),
        ]);

        if ($this->callbackId) {
            $this->closeCallback($order);
        }

        return $order;
    }

    protected function closeCallback(Order $order): void
    {
        $request = CallbackRequest::find($this->callbackId);

        if (! $request || $request->order_id) {
            return;
        }

        OrderPixelData::updateOrCreate(['order_id' => $order->id], [
            'event_id' => Pixel::eventId('pur'),
            'fbp' => $request->fbp,
            'fbc' => $request->fbc,
            'ip' => $request->ip,
            'user_agent' => $request->user_agent,
            'source_url' => $request->source_url,
            'consented' => (bool) $request->consented,
        ]);

        $request->update([
            'order_id' => $order->id,
            'status' => CallbackRequest::CALLED,
            'handled_by' => auth()->id(),
            'handled_at' => now(),
        ]);
    }

    public function render()
    {
        $total = $this->total();

        return view('livewire.admin.orders.create', [
            'lines' => $this->lines(),
            'subtotal' => $this->subtotal(),
            'shipping' => $this->shipping(),
            'total' => $total,
            'cities' => DeliveryCity::active()->withTranslation()->get(),
            'methods' => PaymentMethod::active()->withTranslation()->get(),

            'fits' => PaymentMethod::active()->get()
                ->mapWithKeys(fn (PaymentMethod $m) => [$m->code => $m->fitsTotal($total)])
                ->all(),
            'customers' => $this->customers(),
            'found' => $this->found(),
        ])->layout('layouts.admin', ['title' => __('admin.order_create')]);
    }
}
