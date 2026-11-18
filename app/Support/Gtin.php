<?php

namespace App\Support;

use App\Models\Product;

/*
 | A product's barcode, when we truly have one.
 |
 | Our sku is "<supplier>-<their code>", and for some suppliers that code is
 | the manufacturer's EAN, for others only their own stock number. Merchant
 | Center disapproves an item whose GTIN fails the check digit, and treats a
 | stock number sent as an MPN as a wrong identifier, so a code is offered
 | only when it passes the GS1 check -- otherwise nothing is claimed at all.
 */
class Gtin
{
    public static function of(Product $product): ?string
    {
        $codes = [preg_replace('/\A[a-z0-9]+-/i', '', (string) $product->sku)];

        foreach ($product->relationLoaded('offers') ? $product->offers : $product->offers()->get(['external_id']) as $offer) {
            $codes[] = (string) $offer->external_id;
        }

        foreach ($codes as $code) {
            if (self::valid($code = trim($code))) {
                return $code;
            }
        }

        return null;
    }

    public static function valid(string $code): bool
    {
        if (! preg_match('/\A(?:\d{8}|\d{12,14})\z/', $code) || ltrim($code, '0') === '') {
            return false;
        }

        // 20-29, and 2 / 4 in UPC form, are store-internal ranges, never a manufacturer's code
        if (in_array(strlen($code), [12, 13], true) && preg_match('/\A(?:2|02|04)/', str_pad($code, 13, '0', STR_PAD_LEFT))) {
            return false;
        }

        $digits = array_map('intval', str_split($code));
        $check = array_pop($digits);
        $sum = 0;

        foreach (array_reverse($digits) as $i => $digit) {
            $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }
}
