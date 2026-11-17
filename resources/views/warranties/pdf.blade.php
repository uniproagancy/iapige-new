<!doctype html>
<html lang="{{ $documentLocale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('warranty.document_title', [], $documentLocale) }} — {{ data_get($snapshot, 'certificate.number') }}</title>
    @include('warranties.partials.styles')
</head>
<body>
    @include('warranties.partials.simple-paper')
</body>
</html>
