<?php

namespace App\Services\SalesChat;

use App\Models\Category;
use App\Support\Slug;

class SalesCatalogQuery
{
    public readonly string $phrase;

    /** @var array<string,string> Search word keyed by its normalized form. */
    public readonly array $words;

    private string $question;

    private ?array $questionWords = null;

    private const REQUEST_WORDS = [
        'აბა', 'გთხოვ', 'მინდა', 'მირჩიე', 'მაჩვენე', 'მაქვს', 'მჭირდება', 'მეძებ', 'ვეძებ', 'იყიდება',
        'გაქვთ', 'რა', 'ხომ', 'კი', 'გამარჯობა', 'ფასი', 'ღირს', 'სად', 'რამდენი', 'რომელ', 'რომელი',
        'მომიძებნე', 'მომცე', 'შეგიძლიათ', 'მირჩიეთ', 'მაჩვენეთ', 'გთხოვთ', 'და', 'დაახლოებით',
        'please', 'looking', 'for', 'want', 'show', 'find', 'give', 'need', 'a', 'an', 'the', 'me', 'i',
        'recommend', 'available', 'price', 'minda', 'maqvs', 'macvene', 'machvene', 'mirchie', 'vedzeb',
    ];

    public function __construct(string $query)
    {
        $this->question = mb_substr($query, 0, 1200);
        $query = mb_substr($query, 0, 120);
        $this->phrase = trim((string) preg_replace('/[\s\p{Cc}%_\\\\]+/u', ' ', $query));
        $this->words = self::words($this->phrase, self::REQUEST_WORDS);
    }

    /** @return array{score:int,matched:list<string>}|null */
    public function match(Category $category): ?array
    {
        $best = null;

        foreach ($category->translations as $translation) {
            $slug = preg_replace('/-\d+$/D', '', (string) $translation->slug);
            foreach ([$translation->name, $slug] as $name) {
                $words = self::words((string) $name, ['and', 'და']);
                $matched = array_keys(array_intersect_key($this->words, $words));
                $matchedCount = count($matched);

                if ($matchedCount === 0) {
                    continue;
                }

                $extra = count($words) - $matchedCount;
                $score = $matchedCount * 20 + ($extra === 0 ? 10 : -$extra);

                if ($score > ($best['score'] ?? 0)) {
                    $best = ['score' => $score, 'matched' => $matched];
                }
            }
        }

        return $best;
    }

    /** @return list<string> */
    public function without(array $matched): array
    {
        return array_values(array_diff_key($this->words, array_flip($matched)));
    }

    public function relevance(string $field): int
    {
        $this->questionWords ??= self::evidenceWords($this->question);
        $matches = array_intersect_key(self::evidenceWords($field), $this->questionWords);

        return array_sum(array_map(fn (string $word): int => ctype_digit($word) ? 1 : min(8, mb_strlen($word)), $matches));
    }

    private static function evidenceWords(string $text): array
    {
        $text = (string) preg_replace(
            ['/\be[\s-]+sim\b/iu', '/\bwi[\s-]+fi\b/iu', '/\busb[\s-]+c\b/iu'],
            ['esim', 'wifi', 'usbc'],
            $text,
        );

        return self::words($text, self::REQUEST_WORDS, 100);
    }

    /** @return array<string,string> */
    private static function words(string $text, array $ignore, int $limit = 10): array
    {
        $text = (string) preg_replace('/\bair[ -]+conditioners?\b/iu', 'conditioner', $text);
        $text = (string) preg_replace('/\bwashing[ -]+machines?\b/iu', 'washer', $text);
        $units = ['გბ' => 'gb', 'ტბ' => 'tb', 'მბ' => 'mb', 'დიუმი' => 'inch', 'დუიმი' => 'inch', 'ჰც' => 'hz', 'inches' => 'inch'];
        $text = (string) preg_replace_callback('/(?<!\pN)(\d+)\s*(gb|tb|mb|hz|inches|inch|გბ|ტბ|მბ|დიუმი|დუიმი|ჰც)(?![\pL\pN])/iu',
            fn (array $part): string => $part[1].($units[mb_strtolower($part[2])] ?? mb_strtolower($part[2])), $text);
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) >= 2 && ! in_array($word, $ignore, true)) {
                $key = self::normalized($word);
                if ($key !== '') {
                    $words['word:'.$key] = $word;
                }
            }
        }

        if (isset($words['word:washer'])) {
            unset($words['word:mankan']);
        }

        return array_slice($words, 0, $limit, true);
    }

    private static function normalized(string $word): string
    {
        $latin = strtr(Slug::make($word), ['ts' => 'c', 'kh' => 'x']);

        if (strlen($latin) >= 5) {
            $latin = (string) preg_replace('/(?:ebshi|ebze|ebis|ebi|shi|ze|i|a)$/', '', $latin);
        }

        return match ($latin) {
            'tv', 'tvs', 'televizor', 'televisions' => 'television',
            'telefon', 'smartfon', 'mobilur', 'mobile', 'mobiles', 'phones', 'smartphone', 'smartphones' => 'phone',
            'macivar', 'macivr', 'fridge', 'fridges', 'refrigerators' => 'refrigerator',
            'leptop', 'laptops', 'notebook', 'notebooks' => 'laptop',
            'ac', 'conditioners', 'kondicioner', 'kondincioner' => 'conditioner',
            'washers', 'sarecx' => 'washer',
            'tablet', 'tablets', 'planshet', 'plansheti' => 'tablet',
            default => $latin,
        };
    }
}
