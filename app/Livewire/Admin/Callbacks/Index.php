<?php

namespace App\Livewire\Admin\Callbacks;

use App\Models\CallbackRequest;
use App\Models\Order;
use App\Models\OrderPixelData;
use App\Services\Facebook\Pixel;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'new')]
    public string $status = 'new';

    public ?int $open = null;

    public string $note = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
            $this->open = null;
        }
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== 'new';
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = 'new';
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.admin.callbacks.index', [
            'requests' => CallbackRequest::query()
                ->with(['product' => fn ($q) => $q->withTranslation(), 'user', 'handler'])
                ->when($this->status, fn ($q) => $q->where('status', $this->status))
                ->when($this->search, function ($q) {
                    $term = trim($this->search);
                    $digits = preg_replace('/\D/', '', $term);

                    $q->where(fn ($w) => $w
                        ->where('name', 'like', "%{$term}%")
                        ->when($digits !== '', fn ($p) => $p->orWhere('phone', 'like', "%{$digits}%")));
                })
                ->latest('id')
                ->paginate(25),
            'counts' => $this->counts(),
            'statuses' => [
                CallbackRequest::NEW => __('admin.cb_new'),
                CallbackRequest::CALLED => __('admin.cb_called'),
                CallbackRequest::CLOSED => __('admin.cb_closed'),
            ],
        ])->layout('layouts.admin', ['title' => __('admin.callbacks')]);
    }

    protected function counts(): array
    {
        return CallbackRequest::select('status', DB::raw('count(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();
    }

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
        $this->note = $this->open ? (string) CallbackRequest::find($id)?->note : '';
    }

    public function setStatus(int $id, string $status): void
    {
        $request = CallbackRequest::findOrFail($id);

        $request->update([
            'status' => $status,
            'note' => $this->note ?: $request->note,
            'handled_by' => auth()->id(),
            'handled_at' => now(),
        ]);

        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function createOrder(int $id)
    {
        $request = CallbackRequest::with('product')->findOrFail($id);

        if ($request->order_id) {
            return $this->redirect(route('admin.orders.show', $request->order_id), navigate: true);
        }

        $order = DB::transaction(function () use ($request) {
            $product = $request->product;
            $price = $product ? $product->sellingPrice() : 0.0;

            $order = Order::create([
                'number' => Order::nextNumber(),
                'user_id' => $request->user_id,
                'status' => 'new',
                'name' => $request->name,
                'phone' => $request->phone,
                'email' => $request->user?->email,

                'delivery' => 'courier',
                'payment' => 'cash',
                'comment' => $request->comment,
                'subtotal' => $price,
                'shipping' => 0,
                'total' => $price,
            ]);

            if ($product) {
                $order->items()->create([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => product_title($product->brand?->name, $product->name),
                    'price' => $price,
                    'qty' => 1,
                    'sum' => $price,
                ]);
            }

            OrderPixelData::create([
                'order_id' => $order->id,
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

            return $order;
        });

        return $this->redirect(route('admin.orders.show', $order), navigate: true);
    }

    public function saveNote(int $id): void
    {
        CallbackRequest::whereKey($id)->update(['note' => $this->note ?: null]);

        $this->dispatch('toast', message: __('admin.saved'));
    }

    public function delete(int $id): void
    {
        CallbackRequest::whereKey($id)->delete();

        $this->open = null;
        $this->dispatch('toast', message: __('admin.deleted'));
    }
}
