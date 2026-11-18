<?php

namespace App\Services\Warranties;

use App\Models\WarrantyCertificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWriter;
use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class WarrantyPdf
{
    public function bytes(array $snapshot): string
    {
        return $this->preview($snapshot)->output();
    }

    public function preview(array $snapshot): PdfWriter
    {
        return $this->render($snapshot, false);
    }

    public function sampleBytes(array $snapshot): string
    {
        if (data_get($snapshot, 'certificate.status') !== 'draft'
            || (string) data_get($snapshot, 'seller.tax_id') !== '416353635') {
            throw new InvalidArgumentException(__('warranty.pdf_sample_invalid'));
        }

        // Explicit local sample mode: keep the draft watermark, including when showing the company graphic.
        return $this->render($snapshot, true)->output();
    }

    private function render(array $snapshot, bool $sample): PdfWriter
    {
        $locale = app()->getLocale();
        app()->setLocale('ka');

        try {
            return Pdf::setPaper('a4')
                ->setOptions([
                    'isRemoteEnabled' => false,
                    'isPhpEnabled' => false,
                    'isJavascriptEnabled' => false,
                    'isHtml5ParserEnabled' => true,
                    'isFontSubsettingEnabled' => true,
                    'defaultFont' => 'dejavu sans',
                    'chroot' => [resource_path('dashboard/fonts'), resource_path('warranty')],
                ])
                ->loadView('warranties.pdf', $this->viewData($snapshot, true) + ['isSample' => $sample]);
        } finally {
            app()->setLocale($locale);
        }
    }

    public function filename(WarrantyCertificate $certificate): string
    {
        $number = Str::slug((string) $certificate->number, '-');

        return 'warranty-'.($number !== '' ? $number : 'document')
            .'-v'.max(1, (int) $certificate->revision).'.pdf';
    }

    public function viewData(array $snapshot, bool $pdfMode = false, bool $includePrivateCustomerData = false): array
    {
        if (($snapshot['schema'] ?? null) !== 'iapi.warranty.v1') {
            throw new InvalidArgumentException(__('warranty.pdf_schema_invalid'));
        }

        $dealerForm = app(WarrantyDealerForm::class)->frozen($snapshot['supplier_dealer_form'] ?? null);
        if ($dealerForm !== null && (int) data_get($snapshot, 'item.supplier_id') !== (int) $dealerForm['supplier_id']) {
            throw new InvalidArgumentException(__('warranty.errors.dealer_form_invalid'));
        }

        $isDraft = data_get($snapshot, 'certificate.status') !== 'issued';
        $taxId = data_get($snapshot, 'seller.tax_id');
        if (! $isDraft && (! is_scalar($taxId) || (string) $taxId !== '416353635')) {
            throw new InvalidArgumentException(__('warranty.pdf_seller_invalid'));
        }

        // HTML previews default to a masked phone. Authenticated staff print routes may opt in.
        $phone = data_get($snapshot, 'customer.phone');
        if (! $pdfMode && ! $includePrivateCustomerData && is_scalar($phone)) {
            data_set($snapshot, 'customer.phone', $this->maskedPhone((string) $phone));
        }

        $mark = resource_path('warranty/unipro-stamp-photo.png');
        if (! is_file($mark) || ! is_readable($mark)) {
            throw new RuntimeException(__('warranty.pdf_mark_missing'));
        }
        $markBytes = file_get_contents($mark);
        if (! is_string($markBytes) || ! str_starts_with($markBytes, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException(__('warranty.pdf_mark_missing'));
        }

        return [
            'snapshot' => $snapshot,
            'dealerForm' => $dealerForm,
            'documentLocale' => 'ka',
            'pdfMode' => $pdfMode,
            'regular' => $pdfMode ? $this->font('Linotype-HelveticaNeueLTGEO55Roman.ttf') : null,
            'bold' => $pdfMode ? $this->font('Linotype-HelveticaNeueLTGEO75Bold.ttf') : null,
            // Fixed application asset from the owner's stamp photograph; uploads never become markup.
            'stamp' => 'data:image/png;base64,'.base64_encode($markBytes),
            'isDraft' => $isDraft,
            'documentDates' => [
                'order' => $this->date(data_get($snapshot, 'dates.order_date')),
                'sale' => $this->date(data_get($snapshot, 'dates.sale_date')),
                'delivery' => $this->date(data_get($snapshot, 'dates.delivery_date')),
                'issued' => $this->date(data_get($snapshot, 'certificate.issued_at')),
                'starts' => $this->date(data_get($snapshot, 'warranty.starts_on')),
                'ends' => $this->date(data_get($snapshot, 'warranty.ends_on')),
            ],
            'conditionLines' => $this->lines(data_get($snapshot, 'warranty.conditions')),
        ];
    }

    protected function font(string $filename): string
    {
        $path = resource_path('dashboard/fonts/'.$filename);

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(__('warranty.pdf_font_missing'));
        }

        return str_replace('\\', '/', $path);
    }

    protected function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $part = substr($value, 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $part);

        return $date !== false && $date->format('Y-m-d') === $part
            ? $date->format('d.m.Y') : $value;
    }

    protected function lines(mixed $value): array
    {
        $lines = is_array($value) ? $value : (is_string($value) ? preg_split('/\R/u', $value) : []);

        return array_values(array_filter(
            array_map(fn ($line) => is_string($line) ? trim($line) : '', $lines ?: []),
            fn ($line) => $line !== '',
        ));
    }

    protected function maskedPhone(string $phone): string
    {
        $digits = preg_replace('/\D/u', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        return strlen($digits) > 4 ? '•••• '.substr($digits, -4) : '••••';
    }
}
