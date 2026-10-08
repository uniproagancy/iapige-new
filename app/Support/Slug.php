<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Georgian text loses everything in Str::slug(), so the alphabet is
 * transliterated first and only then slugified.
 */
class Slug
{
    protected const KA = [
        'ა' => 'a',  'ბ' => 'b',  'გ' => 'g',  'დ' => 'd',  'ე' => 'e',
        'ვ' => 'v',  'ზ' => 'z',  'თ' => 't',  'ი' => 'i',  'კ' => 'k',
        'ლ' => 'l',  'მ' => 'm',  'ნ' => 'n',  'ო' => 'o',  'პ' => 'p',
        'ჟ' => 'zh', 'რ' => 'r',  'ს' => 's',  'ტ' => 't',  'უ' => 'u',
        'ფ' => 'f',  'ქ' => 'k',  'ღ' => 'gh', 'ყ' => 'q',  'შ' => 'sh',
        'ჩ' => 'ch', 'ც' => 'ts', 'ძ' => 'dz', 'წ' => 'ts', 'ჭ' => 'ch',
        'ხ' => 'kh', 'ჯ' => 'j',  'ჰ' => 'h',
    ];

    public static function make(?string $value): string
    {
        $value = strtr(mb_strtolower(trim((string) $value)), self::KA);

        return Str::slug($value);
    }
}
