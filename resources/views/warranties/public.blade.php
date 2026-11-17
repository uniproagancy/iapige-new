<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('warranty.document_title', [], 'ka') }} · {{ $number }}</title>
    <style>
        * { box-sizing: border-box; } body { margin: 0; padding: 28px 16px; background: #f5f3ed; color: #153c2f; font: 16px/1.65 Arial, sans-serif; }
        main { max-width: 720px; margin: 0 auto; padding: 32px; background: white; border: 1px solid #e3e7de; border-radius: 20px; }
        .brand { margin: 0 0 28px; color: #f47721; font-size: 23px; font-weight: bold; } h1 { font-size: 25px; line-height: 1.4; } h2 { font-size: 19px; }
        dl { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 24px; margin: 26px 0; } dt { color: #62736b; font-size: 13px; } dd { margin: 3px 0 0; overflow-wrap: anywhere; }
        .download { display: inline-block; padding: 13px 24px; border-radius: 10px; color: white; background: #ed701a; font-weight: bold; text-decoration: none; }
        .note { color: #62736b; font-size: 14px; } footer { margin-top: 30px; border-top: 1px solid #e5e8e1; padding-top: 18px; font-size: 14px; } ul { padding-left: 20px; } a { color: #14583e; }
        @media (max-width: 480px) { main { padding: 22px; } dl { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <p class="brand">IAPI.GE</p>
    <h1>{{ __('warranty.document_title', [], 'ka') }}</h1>
    <h2>{{ $itemName }}</h2>
    <dl>
        <div><dt>{{ __('warranty.public.number', [], 'ka') }}</dt><dd>{{ $number }} · {{ __('warranty.revision', [], 'ka') }} {{ $revision }}</dd></div>
        <div><dt>{{ __('warranty.delivery_date', [], 'ka') }}</dt><dd>{{ $deliveryDate }}</dd></div>
        <div><dt>{{ __('warranty.guarantee', [], 'ka') }}</dt><dd>{{ $termText }}</dd></div>
    </dl>
    <a class="download" href="{{ route('warranty.public.pdf', ['token' => $token]) }}">{{ __('warranty.public.download_pdf', [], 'ka') }}</a>
    <p class="note">{{ __('warranty.public.private_link_notice', [], 'ka') }}</p>
    @if ($attachments !== [])
        <h2>{{ __('warranty.original_documents', [], 'ka') }}</h2>
        <ul>
            @foreach ($attachments as $attachment)
                <li><a href="{{ route('warranty.public.document', ['token' => $token, 'document' => $attachment['id']]) }}">{{ $attachment['label'] }}</a></li>
            @endforeach
        </ul>
    @endif
    <footer><strong>{{ $sellerName }}</strong><br>{{ $sellerPhone }} · {{ $sellerEmail }}</footer>
</main>
</body>
</html>
