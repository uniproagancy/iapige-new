<style>
    .zoommer-dealer-paper { font-size: 9.7pt; line-height: 1.30; }
    .zoommer-dealer-paper .header td { padding-bottom: 7px; }
    .zoommer-dealer-paper .header .title-cell { width: 50%; }
    .zoommer-dealer-paper h1 { font-size: 15pt; }
    .zoommer-dealer-paper h2 { font-size: 11pt; color: #24342d; margin: 10px 0 5px; }
    .zoommer-dealer-paper .dealer-context { margin: 9px 0 10px; }
    .zoommer-dealer-paper .dealer-context td { border-bottom: 1px solid #e0e7df; padding: 3px 0; }
    .zoommer-dealer-paper .dealer-context .label { width: 32%; }
    .zoommer-dealer-paper .small, .zoommer-dealer-paper .kv .label { font-size: 9.5pt; }
    .zoommer-dealer-paper .dealer-scope { margin: 7px 0 9px; font-weight: 700; }
    .zoommer-dealer-paper .dealer-source-body p { margin: 3px 0 5px; }
    .zoommer-dealer-paper .dealer-filled-field { margin: 5px 0; padding-bottom: 5px; border-bottom: 1px solid #d9dfdb; page-break-inside: avoid; }
    .zoommer-dealer-paper .dealer-service { margin: 10px 0 11px; page-break-inside: avoid; }
    .zoommer-dealer-paper .dealer-service p { margin: 3px 0 5px; }
    .zoommer-dealer-paper .dealer-branches { width: 100%; border-collapse: collapse; margin-top: 6px; }
    .zoommer-dealer-paper .dealer-branches td { padding: 3px 7px 3px 0; border-bottom: 1px solid #e0e7df; vertical-align: top; }
    .zoommer-dealer-paper .dealer-branches .city { width: 19%; font-weight: 700; }
    .zoommer-dealer-paper .dealer-ack-label { margin-top: 10px; page-break-after: avoid; }
    .zoommer-dealer-paper .dealer-signature { margin: 9px 0 11px; page-break-before: avoid; page-break-inside: avoid; }
    .zoommer-dealer-paper .dealer-inspection { margin: 9px 0; page-break-inside: avoid; }
    .zoommer-dealer-paper .dealer-mark-row { margin-top: 10px; width: 100%; border-collapse: collapse; page-break-inside: avoid; }
    .zoommer-dealer-paper .dealer-mark-row td { vertical-align: middle; }
    .zoommer-dealer-paper .dealer-mark-row .mark-cell { width: 104px; }
    .zoommer-dealer-paper .stamp { width: 92px; height: 92px; }
    .zoommer-dealer-paper .dealer-footer { margin-top: 9px; padding-top: 6px; border-top: 1px solid #d9dfdb; font-size: 9.5pt; }
</style>
@if ($isDraft)
    <div class="draft-watermark">{{ __($isSample ? 'warranty.sample_watermark' : 'warranty.draft_watermark', [], $documentLocale) }}</div>
@endif
<div class="paper zoommer-dealer-paper">
    <table class="header"><tr><td>
        <div class="brand">IAPI.GE</div>
        @if (array_key_exists('supplier_legal_name', (array) data_get($snapshot, 'item', [])))<div class="small muted">გამყიდველი / დილერი</div>@endif
        <div class="seller-name">{{ $value('seller.name') }}</div>
        <div class="small muted">{{ __('warranty.tax_id', [], $documentLocale) }}: {{ $value('seller.tax_id') }}</div>
        <div class="small muted">{{ $value('seller.address') }}</div>
        <div class="small">{{ $value('seller.phone') }} · <!--email_off-->{{ $value('seller.email') }}<!--/email_off--></div>
    </td><td class="title-cell">
        <h1>{{ $dealerForm['title'] }}</h1>
        <div class="number">№ {{ $value('certificate.number') }}</div>
        <div class="small muted">ზუმერის სადილერო ბარათის პირობები</div>
    </td></tr></table>
    @if ($isDraft)<div class="draft-notice">{{ __($isSample ? 'warranty.sample_notice' : 'warranty.draft_notice', [], $documentLocale) }}</div>@endif
    <p class="dealer-scope">{{ $dealerForm['scope_notice'] }}</p>
    <table class="kv dealer-context">
        <tr><td class="label">{{ __('warranty.customer_name', [], $documentLocale) }}</td><td>{{ $value('customer.name') }}</td></tr>
        <tr><td class="label">{{ __('warranty.phone', [], $documentLocale) }}</td><td>{{ $value('customer.phone') }}</td></tr>
        <tr><td class="label">{{ __('warranty.product', [], $documentLocale) }}</td><td><strong>{{ $value('item.name') }}</strong></td></tr>
        @if (array_key_exists('supplier_legal_name', (array) data_get($snapshot, 'item', [])))<tr><td class="label">{{ __('warranty.supplier', [], $documentLocale) }}</td><td>{{ $value('item.supplier_name') }}</td></tr>@endif
        @if (filled(data_get($snapshot, 'item.supplier_legal_name')))<tr><td class="label">მომწოდებლის იურიდიული დასახელება</td><td>{{ $value('item.supplier_legal_name') }}</td></tr>@endif
        <tr><td class="label">{{ __('warranty.supplier_sku', [], $documentLocale) }}</td><td>{{ $value('item.supplier_sku') }}</td></tr>
        <tr><td class="label">შეკვეთა</td><td>{{ $value('item.order_number') }}</td></tr>
        <tr><td class="label">{{ __('warranty.simple_period_label', [], $documentLocale) }}</td><td><strong>{{ $value('warranty.term_text') }}</strong></td></tr>
    </table>
    <div class="dealer-source-body">
        @foreach ($dealerForm['paragraphs'] as $dealerParagraph)
            @php
                $dealerLine = trim($dealerParagraph);
                $dealerHeading = in_array($dealerLine, ['საგარანტიო მომსახურება:', 'ნივთი შეიცვლება შემდეგ შემთხვევებში:', 'საგარანტიო მომსახურება არ ხორციელდება|უქმდება, თუ:'], true);
                $obsoleteContact = str_starts_with($dealerLine, 'თბილისი:') || str_starts_with($dealerLine, 'ბათუმი:') || str_starts_with($dealerLine, 'ქუთაისი:');
            @endphp
            @if ($dealerLine === 'გარანტია' || $dealerLine === '„ შპს ტექნოლაინი“' || $obsoleteContact)
                {{-- The heading and current service block replace these source-template lines. --}}
            @elseif ($dealerLine === 'საგარანტიო მომსახურებას ახორციელებს:')
                <div class="dealer-service">
                    <h2>{{ $dealerParagraph }} {{ $dealerForm['current_service']['name'] }}</h2>
                    <p><strong>{{ __('warranty.phone', [], $documentLocale) }}:</strong> {{ $dealerForm['current_service']['phone'] }}@if (filled($dealerForm['current_service']['email'] ?? null)) · <!--email_off-->{{ $dealerForm['current_service']['email'] }}<!--/email_off-->@endif</p>
                    <table class="dealer-branches">
                        @foreach ($dealerForm['current_service']['branches'] as $branch)<tr><td class="city">{{ $branch['city'] }}</td><td>{{ $branch['address'] }}</td></tr>@endforeach
                    </table>
                </div>
            @elseif (str_starts_with($dealerLine, 'კომპანია '))
                <p class="dealer-filled-field"><strong>კომპანია:</strong> {{ $value('seller.name') }} · IAPI.GE</p>
            @elseif (str_starts_with($dealerLine, 'კატეგორია, ბრენდი, მოდელი'))
                <p class="dealer-filled-field"><strong>კატეგორია, ბრენდი, მოდელი:</strong> {{ $value('item.name') }}</p>
            @elseif (str_starts_with($dealerLine, 'სერიული | იმეი კოდი'))
                <p class="dealer-filled-field"><strong>სერიული / IMEI კოდი:</strong> {{ $value('item.serial_number') }}</p>
            @elseif (str_starts_with($dealerLine, 'გაყიდვის თარიღი'))
                <p class="dealer-filled-field"><strong>გაყიდვის თარიღი:</strong> {{ $documentDates['sale'] ?? $empty }}</p>
            @elseif (str_starts_with($dealerLine, 'ნივთი ჩავიბარე ვიზუალური დათვალიერებით'))
                <div class="dealer-inspection"><p>{{ $dealerParagraph }}</p><p class="dealer-signature">მომხმარებლის ხელმოწერა: {{ $empty }}</p></div>
            @elseif (str_starts_with($dealerLine, 'გავეცანი, ნივთის შესახებ სრული ინფორმაცია მივიღე'))
                <p class="dealer-ack-label">{{ $dealerParagraph }}</p>
            @elseif (str_starts_with($dealerLine, 'მომხმარებლის ხელმოწერა'))
                <p class="dealer-signature">{{ $dealerParagraph }}</p>
            @elseif ($dealerHeading)
                <h2>{{ $dealerParagraph }}</h2>
            @else
                <p>{{ $dealerParagraph }}</p>
            @endif
        @endforeach
    </div>
    <table class="dealer-mark-row"><tr><td>
        <strong>{{ $value('seller.name') }}</strong><br>
        {{ $value('seller.phone') }} · <!--email_off-->{{ $value('seller.email') }}<!--/email_off-->
    </td><td class="mark-cell">
        @if ((! $isDraft || $isSample) && (string) data_get($snapshot, 'seller.tax_id') === '416353635')<img class="stamp" src="{{ $stamp }}" alt="{{ __('warranty.company_mark', [], $documentLocale) }}">@endif
    </td></tr></table>
    <p class="dealer-footer">{{ filled(data_get($snapshot, 'legal.rights_text')) ? $value('legal.rights_text') : __('warranty.simple_rights_notice', [], $documentLocale) }}</p>
</div>
