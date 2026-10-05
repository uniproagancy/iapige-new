<x-mail::layout :title="__('mail.status_subject_'.$status, ['number' => $order->number])">
    <tr>
        <td style="padding:28px 28px 8px">
            <h1 style="margin:0 0 8px;font-size:21px">{{ __('mail.status_title_'.$status) }}</h1>
            <p style="margin:0;font-size:14px;line-height:1.65;color:#4A5059">
                {{ __('mail.status_text_'.$status, [
                    'name'   => $order->name,
                    'number' => $order->number,
                    'phone'  => config('shop.phone'),
                ]) }}
            </p>
        </td>
    </tr>

    @if ($status === 'shipped')
        <tr>
            <td style="padding:18px 28px 0;font-size:13px;color:#4A5059;line-height:1.6">
                <b>{{ __('checkout.address') }}:</b>
                {{ collect([$order->city, $order->address])->filter()->implode(', ') }}
                <br>
                <b>{{ __('checkout.phone') }}:</b> {{ $order->phone }}
            </td>
        </tr>
    @endif

    <tr>
        <td style="padding:22px 28px 26px">
            <a href="{{ $url }}"
               style="display:inline-block;padding:12px 24px;border-radius:10px;background:#1A1D21;color:#fff;font-size:14px;text-decoration:none">
                {{ __('mail.view_order') }}
            </a>
        </td>
    </tr>
</x-mail::layout>
