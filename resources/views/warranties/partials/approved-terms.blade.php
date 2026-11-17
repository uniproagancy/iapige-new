@if (data_get($snapshot, 'warranty.policy_approved'))
    <div style="page-break-before:always">
        <h1>{{ __('warranty.approved_terms_title', [], $documentLocale) }}</h1>
        <p class="small">№ {{ $value('certificate.number') }} · {{ $value('item.name') }}</p>
        <table class="kv">
            <tr><td class="label">{{ __('warranty.guarantor', [], $documentLocale) }}</td><td>{{ $value('warranty.issuer_name') }}</td></tr>
            <tr><td class="label">{{ __('warranty.address', [], $documentLocale) }}</td><td>{{ $value('warranty.issuer_address') }}</td></tr>
            <tr><td class="label">{{ __('warranty.contact', [], $documentLocale) }}</td><td>{{ $value('warranty.issuer_contact') }}</td></tr>
            <tr><td class="label">{{ __('warranty.territory', [], $documentLocale) }}</td><td>{{ $value('warranty.territory') }}</td></tr>
            <tr><td class="label">{{ __('warranty.simple_period_label', [], $documentLocale) }}</td><td>{{ $value('warranty.term_text') }}</td></tr>
            <tr><td class="label">{{ __('warranty.starts_on', [], $documentLocale) }}</td><td>{{ $documentDates['starts'] ?? $empty }}</td></tr>
            <tr><td class="label">{{ __('warranty.ends_on', [], $documentLocale) }}</td><td>{{ $documentDates['ends'] ?? $empty }}</td></tr>
        </table>
        <h2>{{ __('warranty.coverage', [], $documentLocale) }}</h2>
        <p class="preserve">{{ $value('warranty.coverage') }}</p>
        @if (filled(data_get($snapshot, 'warranty.component_terms')))
            <h3>{{ __('warranty.component_terms', [], $documentLocale) }}</h3>
            <p class="preserve">{{ $value('warranty.component_terms') }}</p>
        @endif
        @if ($conditionLines !== [])
            <h2>{{ __('warranty.conditions', [], $documentLocale) }}</h2>
            <ul>@foreach ($conditionLines as $condition)<li>{{ $condition }}</li>@endforeach</ul>
        @endif
        <h2>{{ __('warranty.claim_procedure_deadlines', [], $documentLocale) }}</h2>
        <p class="preserve">{{ $value('warranty.claim_instructions') }}</p>
        <div class="legal">
            <h2>{{ __('warranty.statutory_rights', [], $documentLocale) }}</h2>
            <p class="preserve">{{ $value('legal.rights_text') }}</p>
            <p class="preserve">{{ $value('legal.seller_responsibility_text') }}</p>
        </div>
        <p class="source">{{ $value('warranty.source_reference') }}</p>
    </div>
@endif
