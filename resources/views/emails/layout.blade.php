<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'IAPI.GE' }}</title>
</head>
{{--
    One table, inline styles, no stylesheet: mail clients strip anything
    cleverer, and a broken confirmation is worse than a plain one.
--}}
<body style="margin:0;padding:0;background:#F6F7F8;font-family:Arial,Helvetica,sans-serif;color:#1A1D21">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F7F8;padding:24px 12px">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden">

                    <tr>
                        <td style="padding:22px 28px;border-bottom:1px solid #E9EBED">
                            <a href="{{ route('home') }}" style="text-decoration:none;color:#1A1D21">
                                <span style="font-size:19px;font-weight:bold;letter-spacing:.14em">IAPI.GE</span>
                            </a>
                        </td>
                    </tr>

                    {{ $slot }}

                    <tr>
                        <td style="padding:18px 28px;border-top:1px solid #E9EBED;font-size:12px;color:#8A9098;line-height:1.7">
                            {{ __('mail.questions') }}
                            <a href="tel:{{ preg_replace('/\s/', '', config('shop.phone')) }}" style="color:#FF6900;text-decoration:none">{{ config('shop.phone') }}</a>
                            <br>
                            <span style="color:#B0B5BA">{{ __('mail.automatic') }}</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
