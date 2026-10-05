<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('invoice.title', ['number' => $order->number]) }}</title>
    <meta name="robots" content="noindex, nofollow">

    <style>
        *{box-sizing:border-box}
        body{
            margin:0;padding:32px 20px;background:#F6F7F8;color:#1A1D21;
            font-family:system-ui,-apple-system,"Segoe UI",sans-serif;font-size:14px;line-height:1.6
        }
        .sheet{max-width:760px;margin:0 auto;background:#fff;border-radius:14px;padding:36px}
        .head{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;border-bottom:2px solid #1A1D21;padding-bottom:18px}
        .logo{font-size:22px;font-weight:700;letter-spacing:.16em}
        .muted{color:#6E747B;font-size:13px}
        h1{font-size:19px;margin:22px 0 4px}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin:22px 0}
        .block__t{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#8A9098;margin-bottom:8px}
        .kv{display:grid;grid-template-columns:130px 1fr;gap:4px 12px;font-size:13px}
        .kv dt{color:#6E747B;margin:0}
        .kv dd{margin:0}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
        table{width:100%;border-collapse:collapse;margin-top:8px}
        th{text-align:left;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#8A9098;padding:8px 0;border-bottom:1px solid #E9EBED}
        td{padding:10px 0;border-bottom:1px solid #E9EBED}
        .r{text-align:right;white-space:nowrap}
        .total td{border:0;border-top:2px solid #1A1D21;font-size:18px;font-weight:700;padding-top:14px}
        .note{margin-top:20px;padding:12px 14px;border-radius:10px;background:#FEF3C7;color:#92400E;font-size:13px}
        .print{
            display:inline-block;margin-top:22px;padding:11px 22px;border:0;border-radius:10px;
            background:#1A1D21;color:#fff;font-size:14px;cursor:pointer
        }

        @media print{
            body{background:#fff;padding:0}
            .sheet{border-radius:0;padding:0;max-width:none}
            .print{display:none}
        }

        @media (max-width:640px){
            body{padding:16px 10px}
            .sheet{padding:22px 18px}
            .grid{grid-template-columns:1fr;gap:18px}
        }
    </style>
</head>
<body>
    <div class="sheet">
        <header class="head">
            <div>
                <div class="logo">IAPI.GE</div>
                <div class="muted">{{ $company['name'] }}</div>
                @if ($company['tax_id'])
                    <div class="muted">{{ __('invoice.tax_id') }}: {{ $company['tax_id'] }}</div>
                @endif
                <div class="muted">{{ $company['address'] }}</div>
            </div>

            <div class="r">
                <h1 style="margin:0">{{ __('invoice.title', ['number' => $order->number]) }}</h1>
                <div class="muted">{{ $order->created_at->format('d.m.Y') }}</div>
                <div class="muted">{{ __('invoice.due_short', ['date' => $dueAt->format('d.m.Y')]) }}</div>
            </div>
        </header>

        <div class="grid">
            <section>
                <div class="block__t">{{ __('invoice.customer') }}</div>
                <dl class="kv">
                    <dt>{{ __('checkout.name') }}</dt><dd>{{ $order->name }}</dd>
                    <dt>{{ __('checkout.phone') }}</dt><dd>{{ $order->phone }}</dd>
                    @if ($order->email)
                        <dt>{{ __('checkout.email') }}</dt><dd>{{ $order->email }}</dd>
                    @endif
                    <dt>{{ __('checkout.address') }}</dt>
                    <dd>{{ collect([$order->city, $order->address])->filter()->implode(', ') }}</dd>
                </dl>
            </section>

            <section>
                <div class="block__t">{{ __('invoice.pay_to') }}</div>
                <dl class="kv">
                    <dt>{{ __('invoice.bank') }}</dt><dd>{{ $company['bank'] }}</dd>
                    <dt>{{ __('invoice.iban') }}</dt><dd class="mono"><b>{{ $company['iban'] }}</b></dd>
                    @if ($company['swift'])
                        <dt>SWIFT</dt><dd class="mono">{{ $company['swift'] }}</dd>
                    @endif
                    <dt>{{ __('invoice.reference') }}</dt><dd class="mono"><b>{{ $order->number }}</b></dd>
                </dl>
            </section>
        </div>

        <table>
            <thead>
                <tr>
                    <th>{{ __('invoice.item') }}</th>
                    <th class="r" style="width:70px">{{ __('admin.qty') }}</th>
                    <th class="r" style="width:110px">{{ __('admin.price') }}</th>
                    <th class="r" style="width:120px">{{ __('checkout.total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>
                            {{ $item->name }}
                            @if ($item->sku)
                                <div class="muted mono" style="font-size:11px">{{ $item->sku }}</div>
                            @endif
                        </td>
                        <td class="r">{{ $item->qty }}</td>
                        <td class="r">{{ money($item->price) }}</td>
                        <td class="r">{{ money($item->price * $item->qty) }}</td>
                    </tr>
                @endforeach

                <tr>
                    <td colspan="3" class="r muted">{{ __('checkout.shipping') }}</td>
                    <td class="r">{{ $order->shipping > 0 ? money($order->shipping) : __('checkout.free') }}</td>
                </tr>

                <tr class="total">
                    <td colspan="3" class="r">{{ __('checkout.total') }}</td>
                    <td class="r">{{ money($order->total) }}</td>
                </tr>
            </tbody>
        </table>

        {{-- without the reference a transfer cannot be matched to this order --}}
        <p class="note">{{ __('invoice.reference_note', ['number' => $order->number]) }}</p>

        <button type="button" class="print" onclick="window.print()">{{ __('invoice.print') }}</button>
    </div>
</body>
</html>
