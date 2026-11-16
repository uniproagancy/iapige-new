<?php

namespace App\Livewire\Admin\Invoices;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Invoices\InvoicePdf;
use App\Support\Figure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 | An invoice with no order behind it -- for a company paying by transfer
 | before anything is ordered, or for goods sold outside the shop.
 |
 | It is drawn by the same template an order's invoice is, from an order that
 | is never saved, so the two always look alike; nothing is written anywhere.
 */
class Create extends Component
{
    public string $number = '';

    public string $date = '';

    public string $buyerName = '';

    public string $buyerTaxId = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public array $lines = [];

    public string $shipping = '';

    public function mount(): void
    {
        $this->number = 'INV-'.now()->format('ymd-Hi');
        $this->date = now()->toDateString();
        $this->addLine();
    }

    public function addLine(): void
    {
        $this->lines[] = ['name' => '', 'sku' => '', 'qty' => '1', 'price' => ''];
    }

    public function removeLine(int $at): void
    {
        unset($this->lines[$at]);
        $this->lines = array_values($this->lines);

        if (! $this->lines) {
            $this->addLine();
        }
    }

    public function download(InvoicePdf $invoices): ?StreamedResponse
    {
        foreach ($this->lines as $at => $line) {
            $this->lines[$at]['price'] = (string) Figure::typed((string) ($line['price'] ?? ''));
        }
        $this->shipping = (string) Figure::typed($this->shipping);

        $this->validate([
            'number' => ['required', 'string', 'max:40'],
            'date' => ['required', 'date'],
            'buyerName' => ['required', 'string', 'max:160'],
            'buyerTaxId' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.sku' => ['nullable', 'string', 'max:64'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.price' => ['required', 'numeric', 'min:0'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'lines.*.name' => __('admin.name'),
            'lines.*.qty' => __('admin.qty'),
            'lines.*.price' => __('admin.price'),
        ]);

        $invoice = $this->invoice();

        return response()->streamDownload(
            fn () => print ($invoices->for($invoice)->output()),
            $invoices->filename($invoice),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /* The order the template reads, built and never saved. */
    protected function invoice(): Order
    {
        $items = new Collection(array_map(fn (array $line) => new OrderItem([
            'name' => trim($line['name']),
            'sku' => trim((string) ($line['sku'] ?? '')) ?: null,
            'qty' => (int) $line['qty'],
            'price' => round((float) $line['price'], 2),
            'sum' => round((float) $line['price'] * (int) $line['qty'], 2),
        ]), $this->lines));

        $subtotal = round((float) $items->sum('sum'), 2);
        $shipping = round((float) ($this->shipping ?: 0), 2);

        $order = new Order;
        $order->forceFill([
            'number' => trim($this->number),
            'name' => trim($this->buyerName),
            'phone' => trim($this->phone),
            'email' => trim($this->email) ?: null,
            'address' => trim($this->address) ?: null,
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'total' => $subtotal + $shipping,
            'is_paid' => false,
        ]);
        $order->created_at = Carbon::parse($this->date)->setTimeFrom(now());
        $order->setAttribute('buyer_tax_id', trim($this->buyerTaxId) ?: null);
        $order->setRelation('items', $items);

        return $order;
    }

    public function total(): float
    {
        $sum = 0.0;

        foreach ($this->lines as $line) {
            $sum += (int) ($line['qty'] ?? 0) * Figure::amount($line['price'] ?? null);
        }

        return round($sum + Figure::amount($this->shipping), 2);
    }

    public function render()
    {
        return view('livewire.admin.invoices.create', ['total' => $this->total()])
            ->layout('layouts.admin', ['title' => __('admin.invoice_free_title')]);
    }
}
