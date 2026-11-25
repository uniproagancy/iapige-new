@php
    $rows = array_filter([
        __('mail.completed_customer') => $order->name,
        __('mail.completed_phone') => $order->phone,
        __('mail.completed_email') => $order->email,
        __('mail.completed_delivery') => trim(
            ($order->delivery === 'pickup' ? __('admin.order_delivery_pickup') : __('admin.order_delivery_courier'))
            .($order->is_regional ? ' · '.__('admin.order_regional') : '')
        ),
        __('mail.completed_address') => trim(implode(', ', array_filter([$order->city, $order->address]))),
        __('mail.completed_payment') => trim($payment.($order->payment_bank ? ' · '.$order->payment_bank : '')),
        __('mail.completed_paid') => $order->is_paid ? __('mail.completed_paid_yes') : __('mail.completed_paid_no'),
        __('mail.completed_placed_at') => $order->created_at?->timezone('Asia/Tbilisi')->format('d.m.Y H:i'),
        __('mail.completed_by') => $by,
    ], fn ($value) => filled($value));
@endphp

@component('emails.layout', ['title' => __('mail.completed_subject', ['number' => $order->number, 'total' => money($order->total)])])
    <tr>
        <td style="padding:28px 28px 8px">
            <h1 style="margin:0 0 8px;font-size:21px">{{ __('mail.completed_title', ['number' => $order->number]) }}</h1>
            <p style="margin:0;font-size:14px;line-height:1.65;color:#4A5059">{{ __('mail.completed_text') }}</p>
        </td>
    </tr>

    <tr>
        <td style="padding:18px 28px 0">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px">
                @foreach ($rows as $label => $value)
                    <tr>
                        <td width="38%" valign="top" style="padding:6px 0;color:#8A9098">{{ $label }}</td>
                        <td style="padding:6px 0;line-height:1.5">{{ $value }}</td>
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>

    <tr>
        <td style="padding:22px 28px 0">
            <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#8A9098;margin-bottom:6px">
                {{ __('mail.order_items') }}
            </div>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px">
                @foreach ($order->items as $item)
                    <tr>
                        <td style="padding:8px 0;border-bottom:1px solid #E9EBED;line-height:1.5">
                            {{ $item->name }}
                            <span style="color:#8A9098">× {{ $item->qty }}</span>
                            @if ($item->sku)
                                <br><span style="color:#8A9098;font-family:ui-monospace,Menlo,monospace;font-size:12px">{{ $item->sku }}</span>
                            @endif
                        </td>
                        <td align="right" valign="top" style="padding:8px 0;border-bottom:1px solid #E9EBED;white-space:nowrap">
                            {{ money($item->price * $item->qty) }}
                        </td>
                    </tr>
                @endforeach

                <tr>
                    <td style="padding:8px 0;color:#6E747B">{{ __('checkout.shipping') }}</td>
                    <td align="right" style="padding:8px 0">{{ money($order->shipping) }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 0 0;border-top:2px solid #1A1D21"><b>{{ __('checkout.total') }}</b></td>
                    <td align="right" style="padding:10px 0 0;border-top:2px solid #1A1D21;font-size:17px"><b>{{ money($order->total) }}</b></td>
                </tr>
            </table>
        </td>
    </tr>

    @if ($order->comment)
        <tr>
            <td style="padding:18px 28px 0">
                <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#8A9098;margin-bottom:6px">
                    {{ __('mail.completed_comment') }}
                </div>
                <div style="font-size:13px;line-height:1.6;color:#4A5059;white-space:pre-line">{{ $order->comment }}</div>
            </td>
        </tr>
    @endif

    <tr>
        <td style="padding:24px 28px 28px">
            <a href="{{ $url }}" style="display:inline-block;background:#FF6900;color:#fff;text-decoration:none;padding:11px 20px;border-radius:8px;font-size:14px">
                {{ __('mail.completed_open') }}
            </a>
        </td>
    </tr>
@endcomponent
