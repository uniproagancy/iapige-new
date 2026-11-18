<style>
    .elite-dealer-paper { font-size: 10pt; line-height: 1.36; }
    .elite-dealer-paper .header td { padding-bottom: 9px; }
    .elite-dealer-paper .header .title-cell { width: 50%; }
    .elite-dealer-paper h1 { font-size: 16pt; }
    .elite-dealer-paper h2 { font-size: 11pt; margin: 12px 0 6px; color: #24342d; }
    .elite-dealer-paper .dealer-context { margin: 11px 0 13px; }
    .elite-dealer-paper .dealer-context td { padding: 4px 0; border-bottom: 1px solid #e0e7df; }
    .elite-dealer-paper .dealer-context .label { width: 31%; }
    .elite-dealer-paper .dealer-service { margin: 10px 0 14px; padding-bottom: 8px; border-bottom: 1px solid #d9dfdb; }
    .elite-dealer-paper .dealer-service h2 { margin-top: 0; }
    .elite-dealer-paper .dealer-service p { margin: 3px 0 5px; }
    .elite-dealer-paper .dealer-intro { margin: 8px 0 11px; }
    .elite-dealer-paper .dealer-source-body p { margin: 3px 0 7px; text-align: left; }
    .elite-dealer-paper .dealer-source-body .dealer-bullet { padding-left: 11px; text-indent: -11px; }
    .elite-dealer-paper .dealer-source-body .dealer-branch { margin: 1px 0 3px; }
    .elite-dealer-paper .dealer-signature-label { margin-top: 14px; page-break-after: avoid; }
    .elite-dealer-paper .dealer-signature-line { margin: 11px 0 16px; page-break-inside: avoid; }
    .elite-dealer-paper .dealer-filled-field { padding-bottom: 6px; margin: 6px 0; border-bottom: 1px solid #d9dfdb; page-break-inside: avoid; }
    .elite-dealer-paper .dealer-stamp { margin-top: 13px; page-break-inside: avoid; }
    .elite-dealer-paper .dealer-stamp .stamp { width: 110px; height: 110px; }
    .elite-dealer-paper .dealer-stamp-blank { height: 55px; }
    .elite-dealer-paper .dealer-footer { margin-top: 15px; padding-top: 7px; border-top: 1px solid #d9dfdb; font-size: 8.5pt; }
</style>
@if ($isDraft)
    <div class="draft-watermark">{{ __($isSample ? 'warranty.sample_watermark' : 'warranty.draft_watermark', [], $documentLocale) }}</div>
@endif
<div class="paper elite-dealer-paper">
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
        <div class="small muted">სს „ელიტ ელექტრონიქსის“ სადილერო ფორმა</div>
    </td></tr></table>
    @if ($isDraft)<div class="draft-notice">{{ __($isSample ? 'warranty.sample_notice' : 'warranty.draft_notice', [], $documentLocale) }}</div>@endif
    <table class="kv dealer-context">
        <tr><td class="label">{{ __('warranty.customer_name', [], $documentLocale) }}</td><td>{{ $value('customer.name') }}</td></tr>
        <tr><td class="label">{{ __('warranty.phone', [], $documentLocale) }}</td><td>{{ $value('customer.phone') }}</td></tr>
        <tr><td class="label">{{ __('warranty.product', [], $documentLocale) }}</td><td><strong>{{ $value('item.name') }}</strong></td></tr>
        <tr><td class="label">{{ __('warranty.supplier', [], $documentLocale) }}</td><td>{{ $value('item.supplier_name') }}</td></tr>
        @if (filled(data_get($snapshot, 'item.supplier_legal_name')))<tr><td class="label">მომწოდებლის იურიდიული დასახელება</td><td>{{ $value('item.supplier_legal_name') }}</td></tr>@endif
        <tr><td class="label">{{ __('warranty.supplier_sku', [], $documentLocale) }}</td><td>{{ $value('item.supplier_sku') }}</td></tr>
        <tr><td class="label">შეკვეთა</td><td>{{ $value('item.order_number') }}</td></tr>
        <tr><td class="label">{{ __('warranty.simple_period_label', [], $documentLocale) }}</td><td><strong>{{ $value('warranty.term_text') }}</strong></td></tr>
    </table>
    <div class="dealer-service">
        <h2>ამ პროდუქტის საგარანტიო მომსახურება: {{ $value('warranty.service.name') }}</h2>
        @if (filled(data_get($snapshot, 'warranty.service.scope')))<p>{{ $value('warranty.service.scope') }}</p>@endif
        <p><strong>{{ __('warranty.phone', [], $documentLocale) }}:</strong> {{ $value('warranty.service.phone') }}@if (filled(data_get($snapshot, 'warranty.service.email'))) · <!--email_off-->{{ $value('warranty.service.email') }}<!--/email_off-->@endif</p>
        <p><strong>{{ __('warranty.address', [], $documentLocale) }}:</strong> {{ $value('warranty.service.address') }}</p>
    </div>
    <div class="dealer-source-body">
        @foreach ($dealerForm['paragraphs'] as $dealerParagraph)
            @php
                $dealerLine = trim($dealerParagraph);
                $dealerHeading = in_array($dealerLine, [
                    'საგარანტიო მომსახურება:',
                    'საგარანტიო მომსახურება არ ხორციელდება/უქმდება თუ:',
                    'ნივთის შეცვლა დაშვებულია მხოლოდ ქვემოთ ჩამოთვლილ შემთხვევებში:',
                    'შპს  ,, ელიტ სერვისის’’ მისამართები :',
                ], true);
                $dealerSignature = str_contains($dealerLine, 'ხელმოწერა, სახელი, გვარი');
                $dealerSignatureLine = preg_match('/\A[-\/\s]+\z/u', $dealerLine) === 1;
            @endphp
            @if ($dealerLine === 'საგარანტიო ტალონი')
                {{-- The original title is already the document heading above. --}}
            @elseif (str_starts_with($dealerLine, 'თქვენს მიერ შეძენილ პროდუქციას ენიჭება გარანტია გაყიდვიდან') && filled(data_get($snapshot, 'warranty.term_text')))
                <p class="dealer-intro">თქვენს მიერ შეძენილ პროდუქციას ენიჭება გარანტია გაყიდვიდან: <strong>{{ $value('warranty.term_text') }}</strong>.</p>
            @elseif (str_starts_with($dealerLine, 'მაღაზია (სავაჭრო ორგანიზაცია):'))
                <p class="dealer-filled-field"><strong>მაღაზია (სავაჭრო ორგანიზაცია):</strong> {{ $value('seller.name') }} · IAPI.GE</p>
            @elseif (str_starts_with($dealerLine, 'მოწყობილობა, ბრენდი, მოდელი:'))
                <p class="dealer-filled-field"><strong>მოწყობილობა, ბრენდი, მოდელი:</strong> {{ $value('item.name') }}</p>
            @elseif (str_starts_with($dealerLine, 'სერიული ნომერი/იმეი კოდი:'))
                <p class="dealer-filled-field"><strong>სერიული ნომერი/იმეი კოდი:</strong> {{ $value('item.serial_number') }}</p>
            @elseif (str_starts_with($dealerLine, 'გაყიდვის თარიღი:'))
                <p class="dealer-filled-field"><strong>გაყიდვის თარიღი:</strong> {{ $documentDates['sale'] ?? $empty }}</p>
            @elseif ($dealerLine === 'სავაჭრო ორგანიზაციის ბეჭედი:')
                <div class="dealer-stamp"><p><strong>{{ $dealerParagraph }}</strong></p>
                    @if ((! $isDraft || $isSample) && (string) data_get($snapshot, 'seller.tax_id') === '416353635')
                        <img class="stamp" src="{{ $stamp }}" alt="{{ __('warranty.company_mark', [], $documentLocale) }}">
                    @else
                        <div class="dealer-stamp-blank"></div>
                    @endif
                </div>
            @elseif ($dealerHeading)
                <h2>{{ $dealerParagraph }}</h2>
            @else
                <p class="{{ $dealerSignature ? 'dealer-signature-label' : ($dealerSignatureLine ? 'dealer-signature-line' : (str_starts_with($dealerLine, '•') ? 'dealer-bullet' : (str_starts_with($dealerLine, 'ქ.') ? 'dealer-branch' : ''))) }}">{{ $dealerParagraph }}</p>
            @endif
        @endforeach
    </div>
    <p class="dealer-footer">{{ filled(data_get($snapshot, 'legal.rights_text')) ? $value('legal.rights_text') : __('warranty.simple_rights_notice', [], $documentLocale) }}</p>
</div>
