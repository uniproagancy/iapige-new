<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('invoice.title', ['number' => $order->number]) }}</title>

    <style>
        @font-face {
            font-family: 'geo';
            font-weight: 400;
            font-style: normal;
            src: url('{{ $regular }}') format('truetype');
        }

        @font-face {
            font-family: 'geo';
            font-weight: 700;
            font-style: normal;
            src: url('{{ $bold }}') format('truetype');
        }

        @page { margin: 28px 34px; }

        body {
            font-family: 'geo', 'dejavu sans', sans-serif;
            font-size: 11px;
            line-height: 1.55;
            color: #1A1D21;
            margin: 0;
        }

        .head { width: 100%; border-bottom: 2px solid #1A1D21; padding-bottom: 12px; }
        .head td { vertical-align: top; }
        .logo { font-size: 18px; font-weight: 700; letter-spacing: .14em; }
        .muted { color: #6E747B; }
        .small { font-size: 10px; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }

        h1 { font-size: 15px; margin: 16px 0 2px; }

        .cols { width: 100%; margin: 14px 0 4px; }
        .cols td { vertical-align: top; width: 50%; padding-right: 14px; }

        .block__t {
            font-size: 10px; font-weight: 700;
            color: #8A9098; margin-bottom: 4px;
        }

        .kv { width: 100%; }
        .kv td { padding: 1px 0; font-size: 10.5px; }
        .kv td.k { color: #6E747B; width: 94px; }

        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th {
            text-align: left; font-size: 10px;
            color: #8A9098; padding: 6px 4px; border-bottom: 1px solid #E9EBED; font-weight: 700;
        }
        table.items td { padding: 7px 4px; border-bottom: 1px solid #E9EBED; }

        .sum { width: 46%; margin-left: 54%; margin-top: 10px; border-collapse: collapse; }
        .sum td { padding: 3px 4px; }
        .sum tr.total td {
            border-top: 2px solid #1A1D21; font-size: 14px; font-weight: 700; padding-top: 8px;
        }

        .note {
            margin-top: 16px; padding: 9px 11px; background: #FEF3C7;
            color: #92400E; font-size: 10px;
        }

        .foot { margin-top: 20px; color: #8A9098; font-size: 9.5px; }

        .paid {
            display: inline-block; padding: 2px 8px; background: #DCFCE7;
            color: #166534; font-size: 10px; font-weight: 700;
        }
    </style>
</head>
<body>

<table class="head">
    <tr>
        <td>
            <div class="logo">{{ config('app.name') }}</div>
            <div class="muted small">
                {{ $company['name'] }}<br>
                @if ($company['tax_id'])
                    {{ __('invoice.tax_id') }}: {{ $company['tax_id'] }}<br>
                @endif
                {{ $company['address'] }}
            </div>
        </td>

        <td class="right">
            <h1>{{ __('invoice.title', ['number' => $order->number]) }}</h1>

            <div class="muted small">
                {{ $order->created_at->format('d.m.Y') }}<br>
                {{ __('invoice.due_short', ['date' => $dueAt->format('d.m.Y')]) }}
            </div>

            @if ($order->is_paid)
                <div class="paid">{{ __('admin.paid') }}</div>
            @endif
        </td>
    </tr>
</table>

<table class="cols">
    <tr>
        <td>
            <div class="block__t">{{ __('invoice.customer') }}</div>

            <table class="kv">
                <tr>
                    <td class="k">{{ __('admin.name') }}</td>
                    <td>{{ $order->name }}</td>
                </tr>
                <tr>
                    <td class="k">{{ __('checkout.phone') }}</td>
                    <td>{{ $order->phone }}</td>
                </tr>
                @if ($order->email)
                    <tr>
                        <td class="k">{{ __('checkout.email') }}</td>
                        <td>{{ $order->email }}</td>
                    </tr>
                @endif
                @if ($order->city || $order->address)
                    <tr>
                        <td class="k">{{ __('admin.delivery') }}</td>
                        <td>{{ trim($order->city.', '.$order->address, ', ') }}</td>
                    </tr>
                @endif
            </table>
        </td>

        <td>
            <div class="block__t">{{ __('invoice.pay_to') }}</div>

            <table class="kv">
                <tr>
                    <td class="k">{{ __('invoice.beneficiary') }}</td>
                    <td>{{ $company['name'] }}</td>
                </tr>
                @if ($company['tax_id'])
                    <tr>
                        <td class="k">{{ __('invoice.tax_id') }}</td>
                        <td>{{ $company['tax_id'] }}</td>
                    </tr>
                @endif
                @foreach ($accounts as $account)
                    <tr>
                        <td class="k">
                            @if ($loop->first) {{ __('invoice.iban') }} @endif
                        </td>
                        <td>
                            @if ($account['bank'])
                                <span class="muted small">{{ $account['bank'] }}</span><br>
                            @endif
                            <span class="nowrap">{{ $account['iban'] }}</span>
                            @if ($account['swift'])
                                <br><span class="muted small">SWIFT: {{ $account['swift'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach

                <tr>
                    <td class="k">{{ __('invoice.reference') }}</td>
                    <td>{{ $order->number }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<div class="block__t" style="margin-top:10px">{{ __('invoice.items') }}</div>

<table class="items">
    <thead>
        <tr>
            <th style="width:48%">{{ __('invoice.item') }}</th>
            <th style="width:16%">{{ __('admin.sku') }}</th>
            <th class="right" style="width:8%">{{ __('admin.qty') }}</th>
            <th class="right" style="width:14%">{{ __('admin.price') }}</th>
            <th class="right" style="width:14%">{{ __('admin.total') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($order->items as $item)
            <tr>
                <td>{{ $item->name }}</td>
                <td class="muted small">{{ $item->sku }}</td>
                <td class="right">{{ $item->qty }}</td>
                <td class="right nowrap">{{ money($item->price) }}</td>
                <td class="right nowrap"><b>{{ money($item->price * $item->qty) }}</b></td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="sum">
    <tr>
        <td class="muted">{{ __('checkout.subtotal') }}</td>
        <td class="right nowrap">{{ money($order->subtotal) }}</td>
    </tr>

    @if ($order->discount)
        <tr>
            <td class="muted">{{ __('checkout.discount') }}</td>
            <td class="right nowrap">&minus;{{ money($order->discount) }}</td>
        </tr>
    @endif

    <tr>
        <td class="muted">{{ __('checkout.shipping') }}</td>
        <td class="right nowrap">
            {{ $order->shipping > 0 ? money($order->shipping) : __('checkout.free') }}
        </td>
    </tr>

    <tr class="total">
        <td>{{ __('invoice.amount') }}</td>
        <td class="right nowrap">{{ money($order->total) }}</td>
    </tr>
</table>

@unless ($order->is_paid)
    <div class="note">{{ __('invoice.reference_note', ['number' => $order->number]) }}</div>
@endunless

<div class="foot">
    {{ $company['name'] }}
    @if ($company['address']) · {{ $company['address'] }} @endif
    @if (config('shop.phone')) · {{ config('shop.phone') }} @endif
</div>

</body>
</html>
