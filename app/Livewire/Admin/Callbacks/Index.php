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

/**
 * Callback requests, worked from the top.
 *
 * The screen opens on what nobody has answered yet, because a lead goes cold
 * in hours and this list is the only place it exists.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'new')]
    public string $status = 'new';

    /** the request opened in place */
    public ?int $open = null;

    public string $note = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
            $this->open = null;
        }
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

    /** @return array<string, int> */
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

    /**
     * Marks a request as handled.
     *
     * Who answered and when is recorded, because a customer who says "somebody
     * called me" deserves an answer better than "which somebody".
     */
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

    /**
     * Turn a phone call into an order.
     *
     * The operator has rung back and agreed the purchase; this writes the
     * order so they do not have to retype the name, the number and the
     * product they were already looking at.
     *
     * What matters beyond the typing is the pixel data. Meta sees a Lead when
     * the number is left and a Purchase when the order is paid, and only
     * recognises them as one person if the Purchase carries the identifiers
     * the browser had. Written from the admin, the Purchase has none of them
     * — a different request, a different machine, no cookies — so the sale was
     * credited to nothing and the campaign only ever got the Lead. They were
     * kept on the call for exactly this moment.
     */
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
                /*
                 * The columns' own defaults, not nulls: both are NOT NULL,
                 * and the operator changes them on the order itself once the
                 * customer has said how they want it.
                 */
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
                    'name' => trim(($product->brand?->name ?? '').' '.$product->name),
                    'price' => $price,
                    'qty' => 1,
                    'sum' => $price,
                ]);
            }

            /*
             * The snapshot the Purchase will be sent with, copied from the
             * call rather than read from this request — this one is the
             * operator's browser, not the customer's.
             */
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
