@php
    $officialTerms = data_get($snapshot, 'warranty.reference_terms');
    $officialSections = [
        'coverage' => 'რას მოიცავს გარანტია',
        'exclusions' => 'საგარანტიო მომსახურების გამონაკლისები',
        'customer_duties' => 'გამოყენება, მონტაჟი და მოვლა',
        'claims' => 'საგარანტიო მომსახურების მიღება',
        'component_terms' => 'ცალკეული ნაწილებისა და დამატებითი მომსახურების პირობები',
    ];
    $displaySections = [];
    if (! data_get($snapshot, 'warranty.policy_approved') && is_array($officialTerms)) {
        foreach ($officialSections as $key => $title) {
            $lines = is_array($officialTerms[$key] ?? null)
                ? array_values(array_filter($officialTerms[$key], fn ($line) => is_string($line) && trim($line) !== '')) : [];
            if ($lines !== []) { $displaySections[$key] = ['title' => $title, 'lines' => $lines]; }
        }
    }
@endphp
@if ($displaySections !== [])
    <div class="official-terms" style="page-break-before:always">
        @if (data_get($snapshot, 'warranty.paper_signatures_required') === true)
            <h1>{{ __('warranty.seller_reference_terms_title', [], $documentLocale) }}</h1>
            <p class="small">{{ $value('seller.name') }} · № {{ $value('certificate.number') }}</p>
        @else
            <h1>მომწოდებლის საგარანტიო პირობები</h1>
            <p class="small">{{ is_string($officialTerms['supplier_name'] ?? null) ? $officialTerms['supplier_name'] : '' }} · № {{ $value('certificate.number') }}</p>
        @endif
        @if ((data_get($snapshot, 'warranty.paper_signatures_required') !== true || ($officialTerms['issuer_kind'] ?? null) === 'seller')
            && is_string($officialTerms['scope_notice'] ?? null) && trim($officialTerms['scope_notice']) !== '')
            <p class="preserve">{{ $officialTerms['scope_notice'] }}</p>
        @endif
        @foreach ($displaySections as $section)
            <div class="official-terms-section">
                <h2>{{ $section['title'] }}</h2>
                <ul>@foreach ($section['lines'] as $line)<li>{{ $line }}</li>@endforeach</ul>
            </div>
        @endforeach
        <p class="small">{{ __('warranty.simple_rights_notice', [], $documentLocale) }}</p>
    </div>
@endif
