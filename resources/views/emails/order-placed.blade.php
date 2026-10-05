<x-mail::layout :title="__('mail.placed_subject', ['number' => $order->number])">
    <tr>
        <td style="padding:28px 28px 8px">
            <h1 style="margin:0 0 8px;font-size:21px">{{ __('mail.placed_title') }}</h1>
            <p style="margin:0;font-size:14px;line-height:1.65;color:#4A5059">
                {{ __('mail.placed_text', ['name' => $order->name]) }}
            </p>
        </td>
    </tr>

    {{-- the number is what a customer quotes when they ring --}}
    <tr>
        <td style="padding:18px 28px 0">
            <table role="presentation" cellpadding="0" cellspacing="0"
                   style="background:#F6F7F8;border-radius:10px">
                <tr><td style="padding:14px 20px">
                    <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#8A9098">
                        {{ __('mail.your_number') }}
                    </div>
                    <div style="font-family:ui-monospace,Menlo,monospace;font-size:19px;font-weight:bold;margin-top:2px">
                        #{{ $order->number }}
                    </div>
                </td></tr>
            </table>
        </td>
    </tr>

    <tr>
        <td style="padding:22px 28px 0">
            <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#8A9098;margin-bottom:8px">
                {{ __('mail.what_next') }}
            </div>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;color:#4A5059">
                @foreach ([
                    __('mail.next_call'),
                    __('mail.next_pack'),
                    __('mail.next_deliver', ['days' => $days]),
                ] as $i => $step)
                    <tr>
                        <td width="28" valign="top" style="padding:5px 0">
                            <span style="display:inline-block;width:20px;height:20px;border-radius:50%;background:#1A1D21;color:#fff;text-align:center;font-size:11px;line-height:20px">{{ $i + 1 }}</span>
                        </td>
                        <td style="padding:5px 0;line-height:1.55">{{ $step }}</td>
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
                    <td align="right" style="padding:10px 0 0;border-top:2px solid #1A1D21;font-size:17px"><b>{{ money($order->total) }}</b></td>
                </tr>
            </table>
        </td>
    </tr>

    <tr>
        <td style="padding:20px 28px 0;font-size:13px;color:#4A5059;line-height:1.6">
            <b>{{ __('checkout.delivery') }}:</b>
            {{ collect([$order->city, $order->address])->filter()->implode(', ') }}
            <br>
            <b>{{ __('checkout.payment') }}:</b>
            {{ $order->is_paid ? __('order.paid_online') : __('order.pay_on_delivery') }}
        </td>
    </tr>

    <tr>
        <td style="padding:22px 28px 26px">
            <a href="{{ $url }}"
               style="display:inline-block;padding:12px 24px;border-radius:10px;background:#1A1D21;color:#fff;font-size:14px;text-decoration:none">
                {{ __('mail.track') }}
            </a>
        </td>
    </tr>
</x-mail::layout>
