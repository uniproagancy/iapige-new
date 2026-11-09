<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Services\Feeds\PrimaryFeatureFormatter;
use DOMDocument;
use DOMXPath;

/**
 * Staged proposal: derive a missing spec from visible, explicitly labelled description text.
 * The caller owns category selection, source identity, existing-value conflicts and persistence.
 */
final class DescriptionSpecs
{
    private const PROFILES = [
        'televisions' => ['screen_cm', 'ekranis-zoma', 'ეკრანის ზომა'],
        'phones' => ['storage', 'shida-mekhsiereba', 'შიდა მეხსიერება'],
        'air_conditioners' => ['area', 'rekomendirebuli-farti', 'რეკომენდირებული ფართი'],
        'heaters' => ['area', 'rekomendirebuli-farti', 'რეკომენდირებული ფართი'],
        'boilers' => ['area', 'rekomendirebuli-farti', 'რეკომენდირებული ფართი'],
        'refrigerators' => ['volume', 'mtliani-motsuloba', 'მთლიანი მოცულობა'],
        'washing_machines' => ['load', 'maksimaluri-chatvirtva', 'მაქსიმალური ჩატვირთვა'],
        'christmas_trees' => ['height', 'simaghle', 'სიმაღლე'],
    ];

    private const LABELS = [
        'screen_cm' => ['ეკრანის დიაგონალი', 'ეკრანის ზომა', 'screen diagonal', 'screen size', 'display diagonal'],
        'storage' => ['შიდა მეხსიერება', 'ჩაშენებული მეხსიერება', 'internal storage', 'built-in storage', 'storage capacity', 'rom'],
        'area' => [
            'რეკომენდირებული ფართობი', 'რეკომენდებული ფართობი', 'რეკომენდირებული ფართი', 'რეკომენდებული ფართი',
            'გასათბობი ფართობი', 'გათბობის ფართობი', 'გაგრილების ფართობი', 'მოქმედების ფართობი',
            'სამუშაო ფართობი', 'ფართობი', 'ფართი', 'recommended area', 'room area', 'heating area', 'cooling area', 'coverage area',
        ],
        'volume' => [
            'მთლიანი სასარგებლო მოცულობა', 'საერთო სასარგებლო მოცულობა', 'მთლიანი ნეტო მოცულობა', 'საერთო ნეტო მოცულობა',
            'მთლიანი მოცულობა', 'საერთო მოცულობა', 'ჯამური მოცულობა', 'total net volume', 'total net capacity', 'total volume', 'total capacity',
        ],
        'load' => ['მაქს. ჩატვირთვა - გარეცხვა', 'რეცხვის მაქსიმალური ჩატვირთვა', 'მაქსიმალური ჩატვირთვა', 'რეცხვის ტევადობა', 'რეცხვის მოცულობა', 'washing capacity', 'wash capacity', 'maximum load'],
        'height' => ['ნაძვის სიმაღლე', 'სიმაღლე', 'tree height', 'height'],
    ];

    public function __construct(private readonly PrimaryFeatureFormatter $formatter = new PrimaryFeatureFormatter) {}

    public function sanitizeIncomingSpecs(string $family, array $specs): array
    {
        if ($family !== 'phones') {
            return $specs;
        }

        return array_values(array_filter($specs, static function (array $row): bool {
            $name = (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower((string) ($row['name'] ?? '')));
            $internalStorage = in_array($name, [
                'შიდამეხსიერება', 'ჩაშენებულიმეხსიერება', 'internalstorage', 'internalmemory', 'builtinstorage', 'storagecapacity', 'rom', 'shidamekhsiereba',
            ], true);

            return ! $internalStorage || preg_match('~\A\h*(?:UFS|eMMC)\h*[0-9]+(?:\.[0-9]+)*\h*\z~iu', (string) ($row['value'] ?? '')) !== 1;
        }));
    }

    public function extractTranslations(string $family, array $translations): ?array
    {
        $proof = [];

        foreach ($translations as $locale => $fields) {
            $html = (string) ($fields['description'] ?? '');

            if (trim(strip_tags($html)) === '') {
                continue;
            }

            $candidate = $this->extract($family, $html);

            if ($candidate === null) {
                if ($this->hasClaim($family, $html)) {
                    return null;
                }

                continue;
            }

            if ($candidate['kind'] === 'area' && $this->areaTitleConflict((string) ($fields['name'] ?? ''), $candidate['value'])) {
                return null;
            }

            $proof[$locale] = $candidate;
        }

        if (! isset($proof['ka']) || count(array_unique(array_column($proof, 'value'))) !== 1) {
            return null;
        }

        return $proof['ka'] + ['locale_evidence' => array_map(static fn (array $candidate): array => $candidate['evidence'], $proof)];
    }

    private function labelPattern(string $kind): string
    {
        $labels = array_map(static fn (string $label): string => str_replace(' ', '\\h+', preg_quote($label, '~')), self::LABELS[$kind]);

        return '~(?<![\p{L}\p{N}])(?:'.implode('|', $labels).')(?![\p{L}\p{N}])(?<declared>\h*\([^()]{1,24}\))?~iu';
    }

    private function hasClaim(string $family, string $html): bool
    {
        $kind = self::PROFILES[$family][0] ?? null;

        if ($kind === null) {
            return false;
        }

        foreach ($this->visibleLines($html) as $line) {
            if (preg_match($this->labelPattern($kind), $line)) {
                return true;
            }

            if ($kind === 'area' && preg_match('~(?:[mმ]\h*(?:²|2|\^2)|sq\.?\h*m|კვ\.?\h*მ)~iu', $line)) {
                return true;
            }
        }

        return false;
    }

    private function areaTitleConflict(string $title, string $expected): bool
    {
        $number = '[0-9]{1,9}(?:[.,][0-9]{1,3})?';
        $unit = '(?:მ\h*(?:²|2|\^2)|m\h*(?:²|2|\^2)|sq\.?\h*m\.?|კვ\.?\h*მ\.?|კვ)';
        preg_match_all('~(?<![\p{L}\p{N}.,])(?<number>'.$number.'\h*(?:[-–—]\h*'.$number.')?)\h*'.$unit.'(?![\p{L}\p{N}])~iu', $title, $matches);

        foreach ($matches['number'] as $quantity) {
            // Shorthand კვ can veto conflicting source text; it can never create a filter value.
            if (RoomArea::normalize($quantity, 'მ²') !== $expected) {
                return true;
            }
        }

        return false;
    }

    public function extract(string $family, string $html): ?array
    {
        $profile = self::PROFILES[$family] ?? null;
        $lines = $this->visibleLines($html);

        if ($profile === null || $lines === [] || $this->multipleModels($lines)) {
            return null;
        }

        [$kind, $attributeCode, $attributeName] = $profile;
        $pattern = $this->labelPattern($kind);
        $found = [];
        $recognized = false;

        foreach ($lines as $index => $line) {
            if (! preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }

            $recognized = true;

            foreach ($matches as $matchIndex => $match) {
                $label = $match[0][0];
                $start = $match[0][1] + strlen($label);
                $end = $matches[$matchIndex + 1][0][1] ?? strlen($line);
                $value = trim(substr($line, $start, $end - $start));
                $declared = isset($match['declared']) ? trim($match['declared'][0], " \t()") : null;
                $prefix = mb_substr(substr($line, 0, $match[0][1]), -70);

                if ($this->qualified($prefix, $kind) || $this->wrongSubjectPrefix($kind, $prefix)) {
                    return null;
                }

                if ($value === '' && isset($lines[$index + 1])) {
                    $value = $lines[$index + 1];
                }

                $value = (string) preg_replace('~\A(?:[:：=]\h*|[-–—]\h+|(?:შეადგენს|არის|is|equals)\h+)~iu', '', $value);

                if ($this->qualified($value, $kind) || ($kind === 'area' && $this->qualified($line, $kind))) {
                    return null;
                }

                $normalized = $this->boundedValue($kind, $value, $declared ?: null);

                // A malformed/ambiguous explicit value must not be outvoted by another paragraph.
                if ($normalized === null) {
                    return null;
                }

                $found[$normalized] = ['line' => $line, 'rule' => 'explicit_label'];
            }
        }

        if (! $recognized && $kind === 'area') {
            $found = $this->proseArea($lines);

            if ($found === []) {
                $found = $this->namedPowerArea($lines);
            }
        }

        if (count($found) !== 1) {
            return null;
        }

        $value = array_key_first($found);

        if ($kind === 'area' && ! $this->allAreasAgree($lines, $value)) {
            return null;
        }

        return [
            'attribute_code' => $attributeCode,
            'attribute_name' => $attributeName,
            'kind' => $kind,
            'value' => $value,
            'display' => $this->formatter->format($kind, $value),
            'evidence' => $found[$value],
        ];
    }

    private function normalize(string $kind, string $value, ?string $unit = null): ?string
    {
        $value = trim($value);
        $value = trim((string) preg_replace('~[.!。]+\z~u', '', $value));

        // A description's 8/10 kg may list variants; the semantic label does not prove wash/dry.
        if ($kind === 'load' && str_contains($value, '/')) {
            return null;
        }

        if ($kind === 'area') {
            return RoomArea::normalize($value, $unit);
        }

        if ($kind === 'screen_cm') {
            // ScreenSize canonicalizes to the existing filter's inches, rather than rounded overlay cm.
            return ScreenSize::normalize($value.($unit !== null ? ' '.$unit : ''));
        }

        $formatted = $this->formatter->format($kind, $value, $unit);

        return $formatted !== '' ? $formatted : null;
    }

    private function wrongSubjectPrefix(string $kind, string $prefix): bool
    {
        $wrong = match ($kind) {
            'volume' => 'საყინულ|სამაცივრე\h+კამერ|მაცივრის\h+კამერ|კამერის|freezer|fridge\h+compartment|refrigerator\h+compartment|compartment',
            'load' => 'შრობის|გაშრობის|drying|dryer|შეფუთვ|ყუთის|package|packaging|shipping|box|carton',
            'height' => 'შეფუთვ|ყუთის|სადგამის|ძირის|package|packaging|shipping|box|carton|stand|base',
            'area' => 'სამონტაჟო|დამონტაჟ|მონტაჟ|installation|mounting|clearance|footprint',
            default => null,
        };

        return $wrong !== null && preg_match('~(?:'.$wrong.')'.($kind === 'area' ? '' : '[\p{L}\h-]*\z').'~iu', trim($prefix)) === 1;
    }

    private function boundedValue(string $kind, string $value, ?string $unit): ?string
    {
        $complete = $this->normalize($kind, $value, $unit);

        if ($complete !== null) {
            return $complete;
        }

        // Some supplier descriptions flatten adjacent, explicitly named fields into one paragraph.
        $following = match ($kind) {
            'load' => 'ბრუნვის\h+სიჩქარე|მაქს\.\h+ჩატვირთვა\h*-\h*გაშრობა|შრობის\h+ტევადობა|spin\h+speed|drying\h+capacity',
            'volume' => 'საყინულე|სამაცივრე|მაცივრის\h+კამერ|ტემპერატურის|თარო|[0-9]+\h+ზონიანი|freezer|temperature|shelves',
            'storage' => 'ოპერატიული\h+მეხსიერება|ram|processor|პროცესორი',
            'screen_cm' => 'რეზოლუცია|განახლების\h+სიხშირე|resolution|refresh\h+rate',
            default => 'ძაბვა|სიმძლავრე|თვითწმენდა|HD\h+ფილტრები|voltage|power',
        };
        $following .= '|ფერი|გარანტია|ენერგო|პროდუქტის\h+ზომა|colour|color|warranty';
        preg_match_all('/\h+/u', $value, $spaces, PREG_OFFSET_CAPTURE);

        foreach (array_slice($spaces[0], 0, 8) as [$space, $offset]) {
            $tail = ltrim(substr($value, $offset + strlen($space)));

            if (preg_match('~\A(?:'.$following.')(?![\p{L}\p{N}])~iu', $tail)) {
                $candidate = $this->normalize($kind, substr($value, 0, $offset), $unit);

                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function proseArea(array $lines): array
    {
        $found = [];
        $pattern = $this->areaPattern();

        foreach ($lines as $line) {
            if (! preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $proof = false;
            foreach ($matches['value'] as [$value, $offset]) {
                if (preg_match('~[+\-–—−]\h*\z~u', substr($line, 0, $offset))) {
                    return [];
                }

                $normalized = RoomArea::normalize($value);

                if ($normalized === null) {
                    return [];
                }

                $left = mb_substr(substr($line, 0, $offset), -55);
                $right = mb_substr(substr($line, $offset + strlen($value)), 0, 55);
                $left = preg_replace('~.*[.!?;]\h*~u', '', $left);
                $right = preg_replace('~[.!?;].*~u', '', $right);
                $context = $left.$value.$right;

                if ($this->qualified($context, 'area') || $this->wrongSubjectPrefix('area', $context)) {
                    return [];
                }

                $proof = $proof || preg_match('~ოთახ|ფართ|სივრც|room|area|coverage~iu', $context) === 1;
                $found[$normalized] = ['line' => $line, 'rule' => 'unique_room_area_prose'];
            }

            if (! $proof) {
                return [];
            }
        }

        return $found;
    }

    private function areaPattern(): string
    {
        $number = '[0-9]{1,9}(?:[.,][0-9]{1,3})?';
        $unit = '(?:მ\h*(?:²|2|\^2)|m\h*(?:²|2|\^2)|sq\.?\h*m\.?|კვ\.?\h*მ\.?|კვადრატული\h+მეტრ(?:ი|ები))';

        return '~(?<![\p{L}\p{N}.,+\-–—−])(?<value>'.$number.'\h*(?:'.$unit.'\h*[-–—]\h*'.$number.'\h*'.$unit.'|[-–—]\h*'.$number.'\h*'.$unit.'|'.$unit.'))(?![\p{L}\p{N}])~iu';
    }

    private function namedPowerArea(array $lines): array
    {
        $found = [];
        $literal = '~(?<![\p{L}\p{N}])(?:სიმძლავრე|power|cooling\h+capacity|heating\h+capacity)\h*[:：]\h*[0-9]{1,6}(?:[ ,][0-9]{3})?\h*BTU(?:\h*/\h*(?:h|სთ))?\h*\(\h*(?<value>[^()]{1,80})\h*\)~iu';

        foreach ($lines as $line) {
            if (! preg_match_all($literal, $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches as $match) {
                $prefix = mb_substr(substr($line, 0, $match[0][1]), -55);
                $raw = trim($match['value'][0]);
                $value = RoomArea::normalize($raw);

                if ($value === null || ! str_contains($value, '-') || $this->qualified($prefix.$raw, 'area')
                    || $this->wrongSubjectPrefix('area', $prefix)
                    || preg_match('~(?:სერიის|series|საჭირო|required|dimensions)\h*\z~iu', trim($prefix))) {
                    return [];
                }

                $found[$value] = ['line' => $line, 'rule' => 'named_power_explicit_parenthesized_area_range'];
            }
        }

        if (count($found) !== 1) {
            return [];
        }

        return $found;
    }

    private function allAreasAgree(array $lines, string $expected): bool
    {
        // Every other explicit area in the description must agree; no bound may become an exact range.
        foreach ($lines as $line) {
            preg_match_all($this->areaPattern(), $line, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches['value'] as [$raw, $offset]) {
                $left = mb_substr(substr($line, 0, $offset), -55);
                $right = mb_substr(substr($line, $offset + strlen($raw)), 0, 55);
                $context = preg_replace('~.*[.!?;]\h*~u', '', $left).$raw.preg_replace('~[.!?;].*~u', '', $right);

                if (RoomArea::normalize($raw) !== $expected || $this->qualified($context, 'area') || $this->wrongSubjectPrefix('area', $context)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function qualified(string $value, string $kind): bool
    {
        if (preg_match('~[<>≤≥≈\~]|\b(?:up\h+to|at\h+least|less\h+than|more\h+than|approximately|approx\.?|around|circa|minimum|min\.?)\b|დაახლოებით|დაახლ\.|არანაკლებ|მინიმ|(?:მეტი|ნაკლები)\h+(?:ვიდრე\h+)?(?=[0-9])|ზე\h+(?:მეტი|ნაკლები)~iu', $value)) {
            return true;
        }

        return $kind === 'area' && preg_match('~მდე|მაქსიმ|\b(?:max(?:imum)?\.?)\b~iu', $value) === 1;
    }

    private function multipleModels(array $lines): bool
    {
        $all = [];

        foreach ($lines as $line) {
            if (preg_match('~(?:მოდელები|models?)\h*[:：=]?\h*(.+)~iu', $line, $match)) {
                preg_match_all('~\b(?=[A-Z0-9-]{3,}\b)(?=[A-Z0-9-]*[A-Z])(?=[A-Z0-9-]*[0-9])[A-Z0-9]+(?:-[A-Z0-9]+)*\b~i', $match[1], $models);

                array_push($all, ...array_map('strtoupper', $models[0]));
            }
        }

        return count(array_unique($all)) > 1;
    }

    private function visibleLines(string $html): array
    {
        if ($html === '' || strlen($html) > 131072 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html)) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;
            $dom->loadHTML('<?xml encoding="utf-8" ?><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($dom);

            foreach ($xpath->query('//script|//style|//template|//noscript|//svg|//*[@hidden or @aria-hidden="true"]') as $node) {
                $node->parentNode?->removeChild($node);
            }

            foreach ($xpath->query('//*[@style]') as $node) {
                if (preg_match('~(?:display\s*:\s*none|visibility\s*:\s*hidden)~i', $node->getAttribute('style'))) {
                    $node->parentNode?->removeChild($node);
                }
            }

            foreach ($xpath->query('//br') as $node) {
                $node->parentNode?->replaceChild($dom->createTextNode("\n"), $node);
            }

            foreach ($xpath->query('//td|//th') as $node) {
                $node->appendChild($dom->createTextNode("\t"));
            }

            foreach ($xpath->query('//p|//div|//li|//tr|//h1|//h2|//h3|//h4|//h5|//h6') as $node) {
                $node->appendChild($dom->createTextNode("\n"));
            }

            $text = $dom->getElementsByTagName('body')->item(0)?->textContent ?? '';
            $text = str_replace(';', "\n", $text);
            $lines = preg_split('/\R/u', $text) ?: [];

            return array_values(array_filter(array_map(static fn (string $line): string => trim((string) preg_replace('/[\h\p{Z}]+/u', ' ', $line)), $lines), static fn (string $line): bool => $line !== ''));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
