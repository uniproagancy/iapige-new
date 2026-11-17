@php
    $isSample = $isSample ?? false;
    $empty = __('warranty.not_entered', [], $documentLocale);
    $value = static function (string $key) use ($snapshot, $empty): string {
        $value = data_get($snapshot, $key);

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : $empty;
    };
@endphp

@if ($isDraft)
    <div class="draft-watermark">{{ __($isSample ? 'warranty.sample_watermark' : 'warranty.draft_watermark', [], $documentLocale) }}</div>
@endif

<div class="paper">
    <table class="header">
        <tr>
            <td>
                <div class="brand">{{ __('warranty.document_brand', [], $documentLocale) }}</div>
                <div class="seller-name">{{ $value('seller.name') }}</div>
                <div class="muted small">{{ __('warranty.tax_id', [], $documentLocale) }}: {{ $value('seller.tax_id') }}</div>
                <div class="muted small">{{ $value('seller.address') }}</div>
            </td>
            <td class="title-cell">
                <h1>{{ __('warranty.document_title', [], $documentLocale) }}</h1>
                <div class="number">{{ $value('certificate.number') }}</div>
                <div class="muted small">{{ __('warranty.revision', [], $documentLocale) }} {{ $value('certificate.revision') }}</div>
            </td>
        </tr>
    </table>

    @if ($isDraft)
        <div class="draft-notice">{{ __($isSample ? 'warranty.sample_notice' : 'warranty.draft_notice', [], $documentLocale) }}</div>
    @endif

    <table class="columns">
        <tr>
            <td>
                <h2>{{ __('warranty.customer', [], $documentLocale) }}</h2>
                <table class="kv">
                    <tr><td class="label">{{ __('warranty.customer_name', [], $documentLocale) }}</td><td>{{ $value('customer.name') }}</td></tr>
                    <tr><td class="label">{{ __('warranty.phone', [], $documentLocale) }}</td><td>{{ $value('customer.phone') }}</td></tr>
                    <tr><td class="label">{{ __('warranty.order_number', [], $documentLocale) }}</td><td>{{ $value('item.order_number') }}</td></tr>
                </table>
            </td>
            <td>
                <h2>{{ __('warranty.seller_contact', [], $documentLocale) }}</h2>
                <table class="kv">
                    <tr><td class="label">{{ __('warranty.phone', [], $documentLocale) }}</td><td>{{ $value('seller.phone') }}</td></tr>
                    <tr><td class="label">{{ __('warranty.email', [], $documentLocale) }}</td><td>{{ $value('seller.email') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <h2>{{ __('warranty.product', [], $documentLocale) }}</h2>
    <div class="product-block">
        <div class="item-name">{{ $value('item.name') }}</div>
        <table class="columns">
            <tr>
                <td>
                    <table class="kv">
                        <tr><td class="label">{{ __('warranty.brand', [], $documentLocale) }}</td><td>{{ $value('item.brand') }}</td></tr>
                        <tr><td class="label">{{ __('warranty.model', [], $documentLocale) }}</td><td>{{ $value('item.model') }}</td></tr>
                        <tr><td class="label">{{ __('warranty.serial_number', [], $documentLocale) }}</td><td>{{ $value('item.serial_number') }}</td></tr>
                        @if (data_get($snapshot, 'item.not_serialized_reason'))
                            <tr><td class="label">{{ __('warranty.not_serialized_reason', [], $documentLocale) }}</td><td>{{ $value('item.not_serialized_reason') }}</td></tr>
                        @endif
                    </table>
                </td>
                <td>
                    <table class="kv">
                        <tr><td class="label">{{ __('warranty.supplier', [], $documentLocale) }}</td><td>{{ $value('item.supplier_name') }}</td></tr>
                        <tr><td class="label">{{ __('warranty.supplier_sku', [], $documentLocale) }}</td><td>{{ $value('item.supplier_sku') }}</td></tr>
                        <tr><td class="label">{{ __('warranty.unit_number', [], $documentLocale) }}</td><td>{{ $value('item.unit_index') }}</td></tr>
                        <tr><td class="label">{{ __('warranty.quantity', [], $documentLocale) }}</td><td>{{ $value('item.quantity') }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <table class="dates">
        <tr>
            <td><div class="date-label">{{ __('warranty.sale_date', [], $documentLocale) }}</div><div class="date-value">{{ $documentDates['sale'] ?? $empty }}</div></td>
            <td><div class="date-label">{{ __('warranty.delivery_date', [], $documentLocale) }}</div><div class="date-value">{{ $documentDates['delivery'] ?? $empty }}</div></td>
            <td><div class="date-label">{{ __('warranty.issued_at', [], $documentLocale) }}</div><div class="date-value">{{ $documentDates['issued'] ?? $empty }}</div></td>
        </tr>
    </table>

    <h2>{{ __('warranty.guarantee', [], $documentLocale) }}</h2>
    <div class="term">{{ $value('warranty.term_text') }}</div>
    <table class="columns">
        <tr>
            <td>
                <table class="kv">
                    <tr><td class="label">{{ __('warranty.guarantor', [], $documentLocale) }}</td><td>{{ $value('warranty.issuer_name') }}</td></tr>
                    <tr><td class="label">{{ __('warranty.address', [], $documentLocale) }}</td><td>{{ $value('warranty.issuer_address') }}</td></tr>
                    <tr><td class="label">{{ __('warranty.contact', [], $documentLocale) }}</td><td>{{ $value('warranty.issuer_contact') }}</td></tr>
                </table>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="label">{{ __('warranty.starts_on', [], $documentLocale) }}</td><td>{{ $documentDates['starts'] ?? $empty }}</td></tr>
                    <tr><td class="label">{{ __('warranty.ends_on', [], $documentLocale) }}</td><td>{{ $documentDates['ends'] ?? $empty }}</td></tr>
                    <tr><td class="label">{{ __('warranty.territory', [], $documentLocale) }}</td><td>{{ $value('warranty.territory') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <h3>{{ __('warranty.coverage', [], $documentLocale) }}</h3>
    <p class="preserve">{{ $value('warranty.coverage') }}</p>
    @if (data_get($snapshot, 'warranty.component_terms'))
        <h3>{{ __('warranty.component_terms', [], $documentLocale) }}</h3>
        <p class="preserve">{{ $value('warranty.component_terms') }}</p>
    @endif
    @if ($conditionLines !== [])
        <h3>{{ __('warranty.conditions', [], $documentLocale) }}</h3>
        <ul>
            @foreach ($conditionLines as $condition)
                <li>{{ $condition }}</li>
            @endforeach
        </ul>
    @endif

    <h2>{{ __('warranty.service_center', [], $documentLocale) }}</h2>
    <table class="kv">
        <tr><td class="label">{{ __('warranty.name', [], $documentLocale) }}</td><td>{{ $value('warranty.service.name') }}</td></tr>
        <tr><td class="label">{{ __('warranty.address', [], $documentLocale) }}</td><td>{{ $value('warranty.service.address') }}</td></tr>
        <tr><td class="label">{{ __('warranty.phone', [], $documentLocale) }}</td><td>{{ $value('warranty.service.phone') }}</td></tr>
        @if (data_get($snapshot, 'warranty.service.email'))
            <tr><td class="label">{{ __('warranty.email', [], $documentLocale) }}</td><td>{{ $value('warranty.service.email') }}</td></tr>
        @endif
    </table>
    <h3>{{ __('warranty.claim_instructions', [], $documentLocale) }}</h3>
    <p class="preserve">{{ $value('warranty.claim_instructions') }}</p>

    @if (data_get($snapshot, 'original_forms', []) !== [])
        <div class="originals">
            <h2>{{ __('warranty.original_documents', [], $documentLocale) }}</h2>
            <ul>
                @foreach (data_get($snapshot, 'original_forms', []) as $original)
                    <li>{{ is_array($original) && is_string($original['label'] ?? null) ? $original['label'] : $empty }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="legal">
        <h2>{{ __('warranty.statutory_rights', [], $documentLocale) }}</h2>
        <p class="preserve">{{ $value('legal.rights_text') }}</p>
        <p class="preserve">{{ $value('legal.seller_responsibility_text') }}</p>
        <p class="preserve">{{ $value('legal.identity_text') }}</p>
    </div>

    <table class="mark-row">
        <tr>
            <td>
                <p class="source"><strong>{{ __('warranty.policy_reference', [], $documentLocale) }}:</strong> {{ $value('warranty.name') }} · {{ __('warranty.revision', [], $documentLocale) }} {{ $value('warranty.version') }}</p>
                <p class="source"><strong>{{ __('warranty.source_reference', [], $documentLocale) }}:</strong> {{ $value('warranty.source_reference') }}</p>
                <p class="source preserve">{{ $value('legal.mark_notice') }}</p>
            </td>
            @if (! $isDraft || $isSample)
                <td class="mark-cell"><img class="stamp" src="{{ $stamp }}" alt="{{ __('warranty.company_mark', [], $documentLocale) }}"></td>
            @endif
        </tr>
    </table>
    <div class="footer">{{ $value('seller.name') }} · {{ $value('seller.tax_id') }} · {{ $value('seller.phone') }} · {{ $value('seller.email') }}</div>
</div>
