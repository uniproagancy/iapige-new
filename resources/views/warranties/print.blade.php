<!doctype html>
<html lang="{{ $documentLocale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('warranty.document_title', [], $documentLocale) }} — {{ data_get($snapshot, 'certificate.number') }}</title>
    @include('warranties.partials.styles')
</head>
<body class="print-preview">
    <div class="print-actions">
        <button type="button" id="warranty-print">{{ __('warranty.print', [], $documentLocale) }}</button>
    </div>
    @include('warranties.partials.simple-paper')
    <script>
        document.getElementById('warranty-print').addEventListener('click', function () {
            window.print();
        });
    </script>
</body>
</html>
