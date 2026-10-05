<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('checkout.redirecting') }}</title>
    <meta name="robots" content="noindex, nofollow">

    <style>
        body{
            margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
            font-family:system-ui,-apple-system,sans-serif;background:#F6F7F8;color:#1A1D21
        }
        .wrap{text-align:center;padding:24px}
        .spin{
            width:34px;height:34px;margin:0 auto 16px;border-radius:50%;
            border:3px solid #E3E6E9;border-top-color:#FF6900;animation:spin .8s linear infinite
        }
        @keyframes spin{to{transform:rotate(360deg)}}
        p{font-size:14px;color:#6E747B;margin:0 0 18px}
        button{
            padding:10px 20px;border:0;border-radius:10px;
            background:#FF6900;color:#fff;font-size:14px;cursor:pointer
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="spin" aria-hidden="true"></div>
        <p>{{ __('checkout.redirecting_to', ['name' => 'Credo']) }}</p>

        {{-- submitted on load; the button is for anyone with JavaScript off --}}
        <form action="{{ $action }}" method="post" id="credoForm">
            <input type="hidden" name="credoinstallment" value='{{ $data }}'>
            <button type="submit">{{ __('checkout.continue') }}</button>
        </form>
    </div>

    <script>
        document.getElementById('credoForm').submit();
    </script>
</body>
</html>
