<?php

declare(strict_types=1);

namespace App\Support;

/** An explicit installed SSD capacity, never RAM, an expansion limit or a sum. */
final class SsdCapacity
{
    public static function normalize(?string $raw, ?string $unit = null): ?string
    {
        if ($raw === null || strlen($raw) > 1024 || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $raw)) {
            return null;
        }
        $text = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $raw));
        if (preg_match('/(?:\b(?:up\s+to|max(?:imum)?|approximately|approx)\b|მდე|მაქსიმ|დაახლ)/iu', $text)) {
            return null;
        }
        $number = '[0-9]{1,9}(?:[.,][0-9]{1,3})?';
        $units = '(?:GB|TB|გბ|ტბ)';
        if (preg_match('/\A'.$number.'\z/u', $text) && $unit !== null && preg_match('/\A'.$units.'\z/iu', trim($unit))) {
            $text .= ' '.trim($unit);
        }
        if (preg_match('/\A(?<number>'.$number.')\s*(?<unit>'.$units.')\z/iu', $text, $whole)) {
            return self::quantity($whole['number'], $whole['unit']);
        }
        if (! preg_match('/\bSSD\b/iu', $text)
            || preg_match('/(?:DDR|SO[ -]?DIMM|\bDIMM\b|\bRAM\b|\bHDD\b|\bSSHD\b|eMMC)/iu', $text)) {
            return null;
        }
        preg_match_all('/(?<![\p{L}\p{N}.,])(?<number>'.$number.')\s*(?<unit>'.$units.')(?![\p{L}\p{N}])/iu', $text, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (count($found) !== 1) {
            return null;
        }
        $prefix = substr($text, 0, $found[0][0][1]);
        if (preg_match('/(?:\b(?:up\s+to|max(?:imum)?|supports?|supported|expandable|expansion|compatible|optional)\b|მდე|მაქსიმ|მხარდაჭერ|თავსებად)/iu', $prefix)) {
            return null;
        }
        // The capacity must describe the installed SSD at the start, or follow
        // only its type/interface. Arbitrary prose cannot supply the quantity.
        if ($prefix !== '' && ! preg_match('/\A\s*(?:(?:SSD|PCIe|NVMe|SATA|M\.2)[®™]?\s*)+\z/iu', $prefix)) {
            return null;
        }
        $suffix = substr($text, $found[0][0][1] + strlen($found[0][0][0]));
        if (preg_match('/\A\s*(?:\+|\/|or\b|ან\b|up\s+to\b|max(?:imum)?\b|მდე|მაქსიმ)/iu', $suffix)) {
            return null;
        }

        return self::quantity($found[0]['number'][0], $found[0]['unit'][0]);
    }

    private static function quantity(string $number, string $unit): ?string
    {
        $amount = (float) str_replace(',', '.', $number);
        if ($amount <= 0) {
            return null;
        }
        $unit = in_array(mb_strtolower($unit), ['tb', 'ტბ'], true) ? 'TB' : 'GB';

        return rtrim(rtrim(number_format($amount, 3, '.', ''), '0'), '.').' '.$unit;
    }
}
