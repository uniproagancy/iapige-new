@php
    $isSample = $isSample ?? false;
    $referenceTerms = data_get($snapshot, 'warranty.reference_terms');
    $hasReferenceTerms = ! data_get($snapshot, 'warranty.policy_approved') && is_array($referenceTerms)
        && collect(['coverage', 'exclusions', 'customer_duties', 'claims', 'component_terms'])
            ->contains(fn ($key) => is_array($referenceTerms[$key] ?? null) && $referenceTerms[$key] !== []);
    $empty = '____________________';
    $value = static function (string $key) use ($snapshot, $empty): string {
        $text = data_get($snapshot, $key);
        return is_scalar($text) && trim((string) $text) !== '' ? (string) $text : $empty;
    };
@endphp
<style>
    body { font-size: 9.5pt; line-height: 1.3; }
    .simple-paper .header td { padding-bottom: 8px; }
    .simple-paper h1 { font-size: 15pt; }
    .simple-paper .header .title-cell { width: 49%; }
    .simple-paper .kv { margin-top: 7px; }
    .simple-paper .kv td { border-bottom: 1px solid #e0e7df; padding: 4px 0; }
    .simple-paper .kv .label { width: 31%; font-size: 9pt; }
    .simple-paper .service-box { margin: 12px 0 9px; padding: 9px 12px; background: #f3f6ef; border-left: 3px solid #155d45; }
    .simple-paper .service-box h2 { margin: 0 0 7px; }
    .simple-paper .service-box p { margin: 2px 0 5px; }
    .simple-paper .mark-row { margin-top: 9px; }
    .simple-paper .stamp { width: 92px; height: 92px; }
    .simple-paper .source { font-size: 8pt; }
</style>
@if ($isDraft)
    <div class="draft-watermark">{{ __($isSample ? 'warranty.sample_watermark' : 'warranty.draft_watermark', [], $documentLocale) }}</div>
@endif
<div class="paper simple-paper">
    <table class="header"><tr><td>
        <div class="brand">IAPI.GE</div>
        <div class="seller-name">{{ $value('seller.name') }}</div>
        <div class="muted small">{{ __('warranty.tax_id', [], $documentLocale) }}: {{ $value('seller.tax_id') }}</div>
        <div class="muted small">{{ $value('seller.address') }}</div>
    </td><td class="title-cell">
        <h1>{{ __('warranty.document_title', [], $documentLocale) }}</h1>
        <div class="number">№ {{ $value('certificate.number') }}</div>
    </td></tr></table>
    @if ($isDraft)<div class="draft-notice">{{ __($isSample ? 'warranty.sample_notice' : 'warranty.draft_notice', [], $documentLocale) }}</div>@endif
    <table class="kv">
        <tr><td class="label">{{ __('warranty.customer_name', [], $documentLocale) }}</td><td>{{ $value('customer.name') }}</td></tr>
        <tr><td class="label">{{ __('warranty.phone', [], $documentLocale) }}</td><td>{{ $value('customer.phone') }}</td></tr>
        <tr><td class="label">{{ __('warranty.product', [], $documentLocale) }}</td><td><strong>{{ $value('item.name') }}</strong></td></tr>
        <tr><td class="label">{{ __('warranty.supplier', [], $documentLocale) }}</td><td>{{ $value('item.supplier_name') }}</td></tr>
        <tr><td class="label">{{ __('warranty.supplier_sku', [], $documentLocale) }}</td><td><strong>{{ $value('item.supplier_sku') }}</strong></td></tr>
        <tr><td class="label">{{ __('warranty.serial_number', [], $documentLocale) }}</td><td>{{ $value('item.serial_number') }}</td></tr>
        <tr><td class="label">{{ __('warranty.order_date', [], $documentLocale) }}</td><td>{{ $documentDates['order'] ?? $empty }}</td></tr>
        <tr><td class="label">{{ __('warranty.sale_date', [], $documentLocale) }}</td><td>{{ $documentDates['sale'] ?? $empty }}</td></tr>
        <tr><td class="label">{{ __('warranty.delivery_date', [], $documentLocale) }}</td><td>{{ $documentDates['delivery'] ?? $empty }}</td></tr>
        <tr><td class="label">{{ __('warranty.simple_period_label', [], $documentLocale) }}</td><td><strong>{{ $value('warranty.term_text') }}</strong></td></tr>
    </table>
    <div class="service-box">
        <h2>სერვისცენტრი / საგარანტიო მიმართვა: {{ $value('warranty.service.name') }}</h2>
        @if (filled(data_get($snapshot, 'warranty.service.scope')))<p class="small">{{ $value('warranty.service.scope') }}</p>@endif
        <p><strong>{{ __('warranty.phone', [], $documentLocale) }}:</strong> {{ $value('warranty.service.phone') }}</p>
        <p><strong>{{ __('warranty.address', [], $documentLocale) }}:</strong> {{ $value('warranty.service.address') }}</p>
        @if (filled(data_get($snapshot, 'warranty.service.email')))<p><!--email_off-->{{ $value('warranty.service.email') }}<!--/email_off--></p>@endif
    </div>
    <h3>{{ data_get($snapshot, 'warranty.policy_approved') || $hasReferenceTerms ? __('warranty.conditions', [], $documentLocale) : 'საგარანტიო მომსახურება — მიმართვის პირობები' }}</h3>
    @if (data_get($snapshot, 'warranty.policy_approved'))
        <p>{{ __('warranty.approved_terms_attached_notice', [], $documentLocale) }}</p>
    @elseif ($hasReferenceTerms)
        <p>დაფარვა, გამონაკლისები და მომსახურების წესები მოცემულია თანდართულ საგარანტიო პირობებში.</p>
    @else
        <p class="preserve">{{ $value('warranty.conditions') }}</p>
    @endif
    @if (data_get($snapshot, 'original_forms', []) !== [])
        <p class="small">{{ __('warranty.original_documents', [], $documentLocale) }}:
            @foreach (data_get($snapshot, 'original_forms', []) as $original)
                {{ is_array($original) && is_string($original['label'] ?? null) ? $original['label'] : '' }}@if (! $loop->last) · @endif
            @endforeach
        </p>
    @endif
    <table class="mark-row"><tr><td>
        <p class="small">{{ $value('seller.name') }}<br>{{ $value('seller.phone') }} · <!--email_off-->{{ $value('seller.email') }}<!--/email_off--></p>
        @if (filled(data_get($snapshot, 'warranty.source_reference')))<p class="source">{{ $value('warranty.source_reference') }}</p>@endif
        <p class="source">{{ __('warranty.simple_rights_notice', [], $documentLocale) }}</p>
    </td>@if ((string) data_get($snapshot, 'seller.tax_id') === '416353635')<td class="mark-cell"><img class="stamp" src="{{ $stamp }}" alt="{{ __('warranty.company_mark', [], $documentLocale) }}"></td>@endif</tr></table>
</div>
@include('warranties.partials.approved-terms')
@include('warranties.partials.official-terms')
