<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('invoice.title', ['number' => $order->number]) }}</title>
</head>
{{--
    Styles are inline because mail clients strip stylesheets, and the layout is
    a single table for the same reason: anything cleverer breaks in Outlook.
--}}
<body style="margin:0;padding:0;background:#F6F7F8;font-family:Arial,Helvetica,sans-serif;color:#1A1D21">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F7F8;padding:24px 12px">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden">

                    <tr>
                        <td style="padding:24px 28px;border-bottom:1px solid #E9EBED">
                            <div style="font-size:19px;font-weight:bold;letter-spacing:.14em">ELIO</div>
                            <div style="font-size:12px;color:#6E747B;margin-top:2px">{{ $company['name'] }}</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px 28px 8px">
                            <h1 style="margin:0 0 6px;font-size:20px">{{ __('invoice.title', ['number' => $order->number]) }}</h1>
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#4A5059">
                                {{ __('invoice.intro', ['name' => $order->name]) }}
                            </p>
                        </td>
                    </tr>

                    {{-- the details without which the payment cannot be made --}}
                    <tr>
                        <td style="padding:16px 28px">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                   style="background:#F6F7F8;border-radius:10px;padding:16px">
                                <tr><td style="padding:16px">
                                    <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#8A9098;margin-bottom:10px">
                                        {{ __('invoice.pay_to') }}
                                    </div>

                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px">
                                        <tr>
                                            <td style="padding:3px 0;color:#6E747B;width:40%">{{ __('invoice.beneficiary') }}</td>
                                            <td style="padding:3px 0"><b>{{ $company['name'] }}</b></td>
                                        </tr>
                                        @if ($company['tax_id'])
                                            <tr>
                                                <td style="padding:3px 0;color:#6E747B">{{ __('invoice.tax_id') }}</td>
                                                <td style="padding:3px 0">{{ $company['tax_id'] }}</td>
                                            </tr>
                                        @endif
                                        <tr>
                                            <td style="padding:3px 0;color:#6E747B">{{ __('invoice.bank') }}</td>
                                            <td style="padding:3px 0">{{ $company['bank'] }}</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:3px 0;color:#6E747B">{{ __('invoice.iban') }}</td>
                                            <td style="padding:3px 0"><b style="font-family:monospace">{{ $company['iban'] }}</b></td>
                                        </tr>
                                        @if ($company['swift'])
                                            <tr>
                                                <td style="padding:3px 0;color:#6E747B">SWIFT</td>
                                                <td style="padding:3px 0;font-family:monospace">{{ $company['swift'] }}</td>
                                            </tr>
                                        @endif
                                        <tr>
                                            <td style="padding:3px 0;color:#6E747B">{{ __('invoice.reference') }}</td>
                                            <td style="padding:3px 0"><b style="font-family:monospace">{{ $order->number }}</b></td>
                                        </tr>
                                        <tr>
                                            <td style="padding:8px 0 3px;color:#6E747B">{{ __('invoice.amount') }}</td>
                                            <td style="padding:8px 0 3px;font-size:18px"><b>{{ money($order->total) }}</b></td>
                                        </tr>
                                    </table>
                                </td></tr>
                            </table>

                            {{-- the reference is what lets us match a transfer to an order --}}
                            <p style="margin:12px 0 0;font-size:12px;color:#92400E;background:#FEF3C7;padding:10px 12px;border-radius:8px;line-height:1.55">
                                {{ __('invoice.reference_note', ['number' => $order->number]) }}
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 28px 0">
                            <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#8A9098;margin-bottom:8px">
                                {{ __('invoice.items') }}
                            </div>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px">
                                @foreach ($order->items as $item)
                                    <tr>
                                        <td style="padding:8px 0;border-bottom:1px solid #E9EBED">
                                            {{ $item->name }}
                                            <span style="color:#8A9098">x {{ $item->qty }}</span>
                                        </td>
                                        <td align="right" style="padding:8px 0;border-bottom:1px solid #E9EBED;white-space:nowrap">
                                            {{ money($item->price * $item->qty) }}
                                        </td>
                                    </tr>
                                @endforeach

                                <tr>
                                    <td style="padding:8px 0;color:#6E747B">{{ __('checkout.shipping') }}</td>
                                    <td align="right" style="padding:8px 0">
                                        {{ $order->shipping > 0 ? money($order->shipping) : __('checkout.free') }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:10px 0 0;border-top:2px solid #1A1D21"><b>{{ __('checkout.total') }}</b></td>
                                    <td align="right" style="padding:10px 0 0;border-top:2px solid #1A1D21;font-size:17px">
                                        <b>{{ money($order->total) }}</b>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 28px">
                            <p style="margin:0 0 16px;font-size:13px;color:#4A5059;line-height:1.6">
                                {{ __('invoice.due', ['date' => $dueAt->translatedFormat('j F')]) }}
                            </p>

                            <a href="{{ $url }}"
                               style="display:inline-block;padding:12px 22px;border-radius:10px;background:#1A1D21;color:#fff;font-size:14px;text-decoration:none">
                                {{ __('invoice.open') }}
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:18px 28px;border-top:1px solid #E9EBED;font-size:12px;color:#8A9098;line-height:1.6">
                            {{ __('invoice.questions') }}
                            <a href="tel:{{ preg_replace('/\s/', '', config('shop.phone')) }}" style="color:#FF6900">{{ config('shop.phone') }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
