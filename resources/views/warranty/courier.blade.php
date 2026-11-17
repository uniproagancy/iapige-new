<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('warranty.title') }} — IAPI.GE</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#161d31;color:#edf0f6;font:16px/1.6 system-ui,sans-serif}a{color:#ff9d4a}header{background:#283046;border-bottom:1px solid #ffffff18;padding:17px 20px}header div,main{max-width:900px;margin:auto}header div{display:flex;justify-content:space-between;gap:16px;align-items:center}header strong{color:#ff7900;font-size:22px}main{padding:24px 18px 48px}h1{font-size:26px;margin:0 0 8px}h2{font-size:20px;margin:0 0 14px}.muted{color:#b3bed0}.intro{margin-bottom:24px}.card{border:1px solid #ffffff20;border-radius:16px;background:#283046;margin:16px 0;padding:22px}.facts{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:18px 0}.facts span{display:block;color:#b3bed0;font-size:13px}.facts strong{overflow-wrap:anywhere}button{border:0;border-radius:10px;background:#ff7900;color:#fff;font:inherit;font-weight:700;padding:13px 20px;cursor:pointer}button:disabled{background:#465168;color:#cad1de;cursor:default}.notice{padding:13px 16px;border-radius:10px;background:#36537940;border:1px solid #98bcf14d;margin:16px 0}.error{background:#b63e4722;border-color:#e86d764d}.number{color:#ffaf67;font-size:13px}.safe{font-size:14px;color:#b3bed0;margin-bottom:0}@media(max-width:575px){main{padding:20px 12px}.card{padding:17px}.facts{grid-template-columns:1fr}button{width:100%}header{padding:14px 12px}h1{font-size:23px}}
    </style>
</head>
<body>
<header><div><strong>IAPI.GE</strong><a href="{{ route('courier.deliveries') }}">{{ __('warranty.courier.back') }}</a></div></header>
<main>
    <div class="intro">
        <h1>{{ __('warranty.title') }}</h1>
        <div class="muted">{{ $delivery->order?->number }} · {{ $delivery->order?->name }}</div>
        <p class="muted">{{ __('warranty.courier.intro') }}</p>
    </div>
    @if (session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="notice error" role="alert"><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    @unless ($sendingEnabled)<div class="notice">{{ __('warranty.courier.sending_disabled') }}</div>@endunless
    @foreach ($releasedDrafts as $draft)
        @php
            $releaseState = $releaseStates[$draft->id];
        @endphp
        <section class="card">
            <div class="number">{{ $draft->number }} · {{ __('warranty.unit_index') }} {{ $draft->unit_index }}</div>
            <h2>{{ $draft->deliveryItem?->name ?: '—' }}</h2>
            <p class="muted">{{ __('warranty.courier.serial_pending') }}</p>
            @if ($releaseState['ready'])
                <form method="POST" action="{{ route('courier.warranties.serial', ['delivery' => $delivery->id, 'certificate' => $draft->id]) }}">
                    @csrf
                    <input type="hidden" name="release_hash" value="{{ $releaseState['release_hash'] }}">
                    <label for="courier-serial-{{ $draft->id }}">{{ __('warranty.courier.serial_input') }}</label>
                    <input id="courier-serial-{{ $draft->id }}" name="serial_number" value="{{ old('certificate_id') == $draft->id ? old('serial_number') : '' }}" maxlength="191" required autocomplete="off" style="display:block;width:100%;padding:12px;margin:8px 0 14px;border-radius:8px;border:1px solid #ffffff40;background:#161d31;color:#fff;font:inherit">
                    <input type="hidden" name="certificate_id" value="{{ $draft->id }}">
                    <button type="submit">{{ __('warranty.courier.serial_save') }}</button>
                </form>
            @else
                <div class="notice error">{{ __('warranty.courier.serial_release_stale') }}</div>
            @endif
        </section>
    @endforeach
    @forelse ($certificates as $certificate)
        @php
            $snapshot = $certificate->snapshot;
            $attempt = $attempts[$certificate->id]['latest'];
            $state = $attempts[$certificate->id]['state'];
            $blocked = $attempt && ! $state['canResend'];
        @endphp
        <section class="card">
            <div class="number">{{ $certificate->number }} · {{ __('warranty.unit_index') }} {{ $certificate->unit_index }}</div>
            <h2>{{ $snapshot['item']['name'] ?? $certificate->deliveryItem?->name ?? '—' }}</h2>
            <div class="facts">
                <div><span>{{ __('warranty.supplier_sku') }}</span><strong>{{ ($snapshot['item']['supplier_sku'] ?? '') ?: '—' }}</strong></div>
                <div><span>{{ __('warranty.fields.serial_number') }}</span><strong>{{ ($snapshot['item']['serial_number'] ?? '') ?: '—' }}</strong></div>
                <div><span>{{ __('warranty.simple_period_label') }}</span><strong>{{ ($snapshot['warranty']['term_text'] ?? '') ?: '—' }}</strong></div>
            </div>
            @if ($attempt)<p class="muted">{{ __('warranty.sms_result.'.$attempt->status) }}</p>@endif
            <form method="POST" action="{{ route('courier.warranties.sms', ['delivery' => $delivery->id, 'certificate' => $certificate->id]) }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ $keys[$certificate->id] }}">
                @if ($attempt)<input type="hidden" name="previous_attempt_id" value="{{ $attempt->id }}">@endif
                <button type="submit" @disabled(! $sendingEnabled || $blocked)>{{ __($attempt ? 'warranty.sms.resend' : 'warranty.courier.send') }}</button>
            </form>
            @if ($attempt)
                <p class="safe">{{ $state['retryAfterSeconds'] > 0 ? __('warranty.sms.resend_wait', ['seconds' => $state['retryAfterSeconds']]) : __('warranty.sms.resend_hint') }}</p>
                @if ($blocked)<a href="{{ route('courier.warranties.index', ['delivery' => $delivery->id]) }}">{{ __('warranty.sms.refresh') }}</a>@endif
            @endif
            <p class="safe">{{ __('warranty.courier.recipient', ['phone' => $delivery->order?->phone]) }}</p>
        </section>
    @empty
        @if ($releasedDrafts->isEmpty())<section class="card"><h2>{{ __('warranty.courier.empty') }}</h2><p class="muted">{{ __('warranty.courier.empty_help') }}</p></section>@endif
    @endforelse
</main>
</body>
</html>
