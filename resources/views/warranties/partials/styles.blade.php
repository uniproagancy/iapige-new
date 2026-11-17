<style>
    @if ($pdfMode)
        @font-face {
            font-family: 'warranty-geo';
            font-style: normal;
            font-weight: 400;
            src: url('{{ $regular }}') format('truetype');
        }
        @font-face {
            font-family: 'warranty-geo';
            font-style: normal;
            font-weight: 700;
            src: url('{{ $bold }}') format('truetype');
        }
    @endif

    @page { size: A4; margin: 27px 34px 31px; }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        color: #24342d;
        font-family: 'warranty-geo', 'DejaVu Sans', 'Segoe UI', sans-serif;
        font-size: 10.5pt;
        line-height: 1.45;
    }
    .paper { position: relative; }
    .header { width: 100%; border-collapse: collapse; border-bottom: 2px solid #ee6b14; }
    .header td { padding: 0 0 12px; vertical-align: top; }
    .header .title-cell { width: 53%; text-align: right; }
    .brand { font-size: 20pt; font-weight: 700; letter-spacing: .06em; color: #155d45; }
    .seller-name { font-size: 10.5pt; font-weight: 700; margin-top: 3px; }
    h1 { font-size: 17pt; line-height: 1.35; margin: 0 0 5px; font-weight: 700; }
    h2 { font-size: 12pt; margin: 15px 0 6px; color: #155d45; page-break-after: avoid; }
    h3 { font-size: 10.5pt; margin: 9px 0 4px; page-break-after: avoid; }
    p { margin: 3px 0 7px; }
    .muted { color: #606b65; }
    .small { font-size: 9pt; }
    .number { font-size: 11pt; font-weight: 700; }
    .draft-notice { margin: 13px 0; padding: 9px 11px; border: 1px solid #ba6442; color: #92412e; background: #fff7ee; font-weight: 700; }
    .draft-watermark { position: fixed; top: 310px; left: 82px; width: 540px; text-align: center; font-size: 59px; color: #e7e4df; opacity: .38; transform: rotate(-25deg); z-index: -1; }
    .columns { width: 100%; border-collapse: collapse; margin-top: 12px; }
    .columns > tbody > tr > td { width: 50%; vertical-align: top; padding: 0 13px 0 0; }
    .columns > tbody > tr > td + td { padding: 0 0 0 13px; }
    .kv { width: 100%; table-layout: fixed; border-collapse: collapse; }
    .kv td { padding: 3px 0; vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; }
    .kv .label { width: 37%; padding-right: 9px; color: #606b65; font-size: 9pt; }
    .item-name { font-size: 13pt; font-weight: 700; margin: 4px 0 8px; word-wrap: break-word; }
    .product-block { border-bottom: 1px solid #dfe7df; padding: 1px 0 10px; margin-bottom: 9px; }
    .dates { width: 100%; border-collapse: collapse; margin: 10px 0 13px; }
    .dates td { width: 33.33%; padding: 7px 9px; border: 1px solid #dfe7df; vertical-align: top; }
    .date-label { color: #606b65; font-size: 9pt; }
    .date-value { font-size: 10.5pt; font-weight: 700; margin-top: 3px; }
    .term { font-size: 17pt; font-weight: 700; color: #155d45; }
    .preserve { white-space: pre-line; word-wrap: break-word; overflow-wrap: anywhere; }
    ul { padding-left: 18px; margin: 4px 0 8px; }
    li { margin-bottom: 4px; word-wrap: break-word; }
    .legal { margin-top: 15px; border-top: 1px solid #dfe7df; padding-top: 3px; }
    .legal p { font-size: 9.5pt; }
    .source { font-size: 9pt; word-wrap: break-word; overflow-wrap: anywhere; }
    .mark-row { width: 100%; border-collapse: collapse; margin-top: 14px; page-break-inside: avoid; }
    .mark-row td { vertical-align: middle; }
    .mark-cell { width: 140px; text-align: right; }
    .stamp { width: 126px; height: 126px; }
    .footer { margin-top: 12px; padding-top: 7px; border-top: 1px solid #dfe7df; color: #606b65; font-size: 8.5pt; }
    .originals { page-break-inside: avoid; }
    .empty { color: #806249; }
    .print-preview { background: #f2f3ef; padding: 25px 16px; font-size: 13px; }
    .print-preview .paper { max-width: 800px; margin: 0 auto; padding: 34px; background: #fff; }
    .print-preview .draft-watermark { display: none; }
    .print-actions { max-width: 800px; margin: 0 auto 13px; text-align: right; }
    .print-actions button { background: #155d45; border: 0; border-radius: 7px; padding: 11px 21px; color: #fff; font-size: 14px; cursor: pointer; }
    @media (max-width: 620px) {
        .print-preview { padding: 12px 7px; }
        .print-preview .paper { padding: 20px 14px; }
        .header .title-cell { width: 54%; }
        h1 { font-size: 16px; }
        .brand { font-size: 18px; }
        .kv .label { width: 42%; }
    }
    @media print {
        .print-preview { background: #fff; padding: 0; font-size: 10.5px; }
        .print-preview .paper { max-width: none; padding: 0; }
        .print-actions { display: none; }
        .print-preview .draft-watermark { display: block; }
    }
</style>
