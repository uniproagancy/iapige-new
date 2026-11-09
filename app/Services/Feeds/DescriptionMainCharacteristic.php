<?php

declare(strict_types=1);

namespace App\Services\Feeds;

use App\Models\Product;
use DOMDocument;
use DOMXPath;

/** Read only a configured, explicitly named property from the CURRENT description. */
class DescriptionMainCharacteristic
{
    public function __construct(protected PrimaryFeatureFormatter $formatter = new PrimaryFeatureFormatter) {}

    public function selection(Product $product, array $profile): ?array
    {
        $rule = $profile['description'] ?? null;
        if ($rule === null || ! $product->relationLoaded('translations')) {
            return null;
        }
        $ka = $product->translations->firstWhere('locale', 'ka');
        if ($ka === null || trim((string) $ka->description) === '') {
            return null;
        }
        $name = (string) ($ka->name ?? '');
        $first = $this->extract($profile['kind'], (string) $ka->description, $rule, $name);
        if ($first === null) {
            return null;
        }
        $hashes = [];
        foreach ($product->translations as $translation) {
            $html = (string) $translation->description;
            if (trim($html) === '') {
                continue;
            }
            $hashes[$translation->locale] = hash('sha256', $html);
            $other = $this->extract($profile['kind'], $html, $rule, $name);
            if ($other === null) {
                if ($this->hasRelevantClaim($profile['kind'], $html, $rule, $name)) {
                    return null;
                }

                continue;
            }
            if (! $this->formatter->equivalent($profile['kind'], $first['measurement'], $other['measurement'])) {
                return null;
            }
        }

        return ['profile_key' => $profile['profile_key'], 'kind' => $profile['kind'],
            'label' => $first['label'], 'measurement' => $first['measurement'],
            'source_ids' => [], 'source_attribute_ids' => [], 'source_type' => 'description',
            'description_sha256s' => $hashes, 'evidence' => $first['evidence']];
    }

    public function extract(string $kind, string $html, array $rule, string $name = ''): ?array
    {
        $text = $this->visible($html);
        if ($text === '' || $this->modelConflict($text, $name)) {
            return null;
        }
        if ($kind === 'furniture_dimensions') {
            return $this->dimensions($text);
        }
        $qualifiedArea = $kind === 'area' ? $this->qualifiedArea($text) : ['present' => false, 'selection' => null];
        if ($qualifiedArea['present'] && $qualifiedArea['selection'] === null) {
            return null;
        }
        $headers = $rule['headers'] ?? [];
        if ($headers === []) {
            return null;
        }
        usort($headers, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '~(?<!\p{L})(?<header>'.implode('|', array_map(fn ($x) => preg_quote($x, '~'), $headers)).')\h*(?:\((?<unit>[^():\n]{1,20})\))?\h*[:：]\s*~iu';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        $found = $qualifiedArea['selection'] !== null ? [$qualifiedArea['selection']] : [];
        foreach ($matches as $match) {
            $offset = $match[0][1];
            if ($this->excludedContext($text, $offset)) {
                continue;
            }
            // Regex offsets are bytes; mb_strcut preserves UTF-8 at the bounded fragment end.
            $fragment = mb_strcut($text, $offset + strlen($match[0][0]), 1500, 'UTF-8');
            $headerUnit = $match['unit'][0] ?? null;
            if ($kind === 'recommended_age') {
                $value = $this->age($fragment);
            } elseif ($kind === 'press_force') {
                $value = $this->pressForce($fragment);
            } else {
                $fields = preg_split('/[\n;]|[.!?]\s+(?=\p{Lu})/u', $fragment, 2);
                if ($fields === false) {
                    return null;
                }
                $field = trim($fields[0]);
                // Prefer the complete field. Shared formatters own reviewed compounds and categorical kinds.
                $value = $this->normalized($kind, $field, $headerUnit);
                if ($value === null && ! in_array($kind, ['compatibility_model', 'platform', 'native_resolution', 'video_resolution', 'wifi_standard',
                    'switch_type', 'printing_technology', 'socket', 'strap_size', 'pickup_pattern', 'laptop_fit'], true)) {
                    $raw = $this->quantityPrefix($fragment, $headerUnit);
                    $value = $raw !== null ? $this->normalized($kind, $raw, $headerUnit) : null;
                }
            }
            if ($value !== null) {
                $value['evidence'] = [['header' => $match['header'][0], 'literal' => mb_substr($fragment, 0, 100)]];
                $found[] = $value;
            } elseif (preg_match('/\A\h*(?:[0-9]|up\h+to|~|დაახლ)/iu', $fragment)) {
                // An explicit but unprovable competing claim must not be silently ignored.
                return null;
            }
        }
        if ($found === []) {
            return null;
        }
        $first = $found[0];
        foreach ($found as $other) {
            if (! $this->formatter->equivalent($kind, $first['measurement'], $other['measurement'])) {
                return null;
            }
        }

        return $first;
    }

    /** The printed area range is the evidence; the BTU figure is never converted. */
    protected function qualifiedArea(string $text): array
    {
        $number = '[0-9]{1,4}(?:[.,][0-9]{1,2})?';
        $unit = '(?:მ[²2]|m[²2]|კვ\.?\h*მ\.?)';
        $power = '(?:(?:სიმძლავრე|სიმლავრე|power)(?!\p{L})\h*[:：]?\h*|(?:სერია|series)\h*[:：]\h*[A-Z][A-Z\h-]{0,35}?\h+)';
        $pattern = '~(?<!\p{L})'.$power.'[0-9]{3,7}\h*BTU(?:\h*/\h*h)?\h*\((?<area>[^()\n]{1,100})\)~iu';
        preg_match_all($pattern, $text, $fields, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $claims = [];
        foreach ($fields as $field) {
            if ($this->excludedContext($text, $field[0][1]) || ! preg_match('~'.$unit.'~iu', $field['area'][0])) {
                continue;
            }
            if (! preg_match('~\A\h*(?<low>'.$number.')\h*(?:'.$unit.')?\h*[-–—]\h*(?<high>'.$number.')\h*'.$unit.'\h*\z~iu', $field['area'][0], $range)) {
                return ['present' => true, 'selection' => null];
            }
            $low = (float) str_replace(',', '.', $range['low']);
            $high = (float) str_replace(',', '.', $range['high']);
            if ($low <= 0 || $high <= $low) {
                return ['present' => true, 'selection' => null];
            }
            $selection = $this->normalized('area', $range['low'].'–'.$range['high'].' მ²');
            if ($selection === null) {
                return ['present' => true, 'selection' => null];
            }
            $selection['evidence'] = [['named_btu_literal_area' => $field['area'][0]]];
            $claims[] = $selection;
        }
        if ($claims === []) {
            return ['present' => false, 'selection' => null];
        }
        // Independent prose must identify the appliance's room coverage, not installation space.
        $prose = '~(?<!\p{L})კონდიციონერი\h+(?:იდეალურად\h+მუშაობს|იდეალურია)\h+(?<maximum>'.$number.')\h*'.$unit.'\h*-?\h*მდე\h+(?:ფართის\h+პირობებში|ფართისთვის)(?!\p{L})~iu';
        preg_match_all($prose, $text, $rooms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $rooms = array_values(array_filter($rooms, fn (array $room) => ! $this->excludedContext($text, $room[0][1])));
        if ($rooms === []) {
            return ['present' => true, 'selection' => null];
        }
        $first = $claims[0];
        foreach ($claims as $claim) {
            if (! $this->formatter->equivalent('area', $first['measurement'], $claim['measurement'])) {
                return ['present' => true, 'selection' => null];
            }
        }
        foreach ($rooms as $room) {
            $maximum = (float) str_replace(',', '.', $room['maximum'][0]);
            $clause = $room[0][0];
            $prefix = mb_substr(substr($text, 0, $room[0][1]), -65);
            if (abs($maximum - $first['measurement']['high']) > 0.00001
                || preg_match('/(?:არ\h+არის|არ\h+მუშაობს|ვერ|not|never)\h*\z/iu', $prefix)
                || preg_match('/(?:მონტაჟ|დამონტაჟ|სამონტაჟო|installation|clearance)/iu', $clause)) {
                return ['present' => true, 'selection' => null];
            }
        }
        $first['evidence'][] = ['independent_room_maximum' => $rooms[0]['maximum'][0].' მ²'];

        return ['present' => true, 'selection' => $first];
    }

    protected function quantityPrefix(string $fragment, ?string $headerUnit): ?string
    {
        $number = '[0-9]{1,9}(?:[.,][0-9]{1,4})?';
        $unit = '(?:m²|m2|მ²|მ2|kPa|MPa|Pa|AW|kW|W|bar|kg|mm|cm|mAh|Ah|Wh|V|A|L|ml|inch(?:es)?|in|hours?|hrs?|h|mins?|minutes?|g\h*/\h*min|["″]|ვატი|ვტ|კვტ|ბარი|კგ|მმ|სმ|მეტრი|მ|m|წუთი|წთ|საათი|სთ|გ\h*/\h*წთ|ლიტრი|ლ|მლ|ნმ|ტონა)';
        $boundary = '(?![\p{L}\p{N}])';
        $quantity = '(?:(?:up\h+to|approx\.?|\~)\h*)?'.$number.'\h*(?:'.$unit.$boundary.')?(?:\h*[-–—]\h*'.$number.'\h*(?:'.$unit.$boundary.')?)?(?:\h+RMS\b)?';
        if (! preg_match('~\A\s*(?<value>'.$quantity.')(?:-?მდე)?~iu', $fragment, $match)) {
            return null;
        }
        $raw = trim($match[0]);
        $tail = substr($fragment, strlen($match[0]));
        if ($this->competingTail($tail) || preg_match('/\A[\p{L}\p{N}]/u', $tail)) {
            return null;
        }
        if ($headerUnit === null && ! preg_match('~'.$unit.'~iu', $raw)) {
            return null;
        }

        return $raw;
    }

    protected function normalized(string $kind, string $raw, ?string $unit = null): ?array
    {
        $measurement = $this->formatter->measure($kind, $raw, $unit);
        $label = $this->formatter->format($kind, $raw, $unit);

        return $measurement !== null && $label !== '' ? ['label' => $label, 'measurement' => $measurement] : null;
    }

    protected function dimensions(string $text): ?array
    {
        preg_match_all('/(?<!\p{L})(?:ზომები|dimensions)\s*[:：]/iu', $text, $sections, PREG_OFFSET_CAPTURE);
        $segments = [];
        foreach ($sections[0] as $index => [$header, $offset]) {
            $prefix = mb_substr(substr($text, 0, $offset), -60);
            if (preg_match('/(?:შეფუთვის|ყუთის|კარტონის|შიდა|სკამის|მაგიდის|მატრასის|შეფუთვა|package|carton|box|inner|internal|chair|table)\s*\z/iu', $prefix)) {
                continue;
            }
            $start = $offset + strlen($header);
            $end = $sections[0][$index + 1][1] ?? strlen($text);
            $segment = substr($text, $start, $end - $start);
            $stop = preg_match('/(?:შეფუთვა|ყუთი|კარტონი|package|carton|box|(?:შეფუთვის|ყუთის|კარტონის|სკამის|მაგიდის)\s+(?:მახასიათებლები|მონაცემები|პარამეტრები))\s*[:：]/iu', $segment, $blocked, PREG_OFFSET_CAPTURE);
            $segments[] = $stop ? substr($segment, 0, $blocked[0][1]) : $segment;
        }
        if ($segments === []) {
            return null;
        }
        $text = implode("\n", $segments);
        $axes = [];
        preg_match_all('~(?<axis>სიმაღლე|სიგრძე|სიგანე|სიღრმე)\h*[:：]\s*(?<value>[0-9]+(?:[.,][0-9]+)?\h*(?:მმ|სმ|მეტრი|მ|mm|cm|m))(?![\p{L}\p{N}])~iu', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $prefix = mb_substr(substr($text, 0, $match[0][1]), -45);
            if ($this->blockedSection($text, $match[0][1]) || preg_match('/(?:შეფუთვის|ყუთის|კარტონის|შიდა|სკამის|მაგიდის|package|carton|shipping|inner|internal)\s*\z/iu', $prefix)) {
                continue;
            }
            $raw = $match['value'][0];
            $tail = substr($text, $match[0][1] + strlen($match[0][0]));
            if ($this->competingTail($tail)) {
                return null;
            }
            $length = $this->formatter->measure('height', $raw);
            if ($length === null) {
                $mm = $this->formatter->measure('diameter', $raw);
                $length = $mm !== null ? ['low' => $mm['low'] / 10, 'high' => null] : null;
            }
            if ($length === null || $length['high'] !== null || $length['low'] <= 0) {
                return null;
            }
            $axis = $match['axis'][0];
            $value = round($length['low'], 4);
            if (isset($axes[$axis]) && $axes[$axis] !== $value) {
                return null;
            }
            $axes[$axis] = $value;
        }
        $order = isset($axes['სიღრმე']) ? ['სიგანე', 'სიღრმე', 'სიმაღლე'] : ['სიგრძე', 'სიგანე', 'სიმაღლე'];
        if (count($axes) !== 3 || array_diff($order, array_keys($axes)) !== []) {
            return null;
        }
        $numbers = array_map(fn ($axis) => $this->number($axes[$axis]), $order);
        $label = implode(' × ', $numbers).' სმ ('.implode(' × ', $order).')';
        if (mb_strlen($label) > 90) {
            return null;
        }

        return ['label' => $label, 'measurement' => ['text' => $label, 'unit' => 'furniture_dimensions', 'source_unit' => 'cm'],
            'evidence' => [['named_axes_cm' => $axes]]];
    }

    protected function age(string $fragment): ?array
    {
        if (! preg_match('/\A\s*(?<first>[0-9]{1,2})(?:\s*[-–—]\s*(?<second>[0-9]{1,2}))?\s*(?<plus>\+)?\s*(?<unit>თვე|თვის|წელი|წლის|months?|years?)(?!\p{L})/iu', $fragment, $m)) {
            return null;
        }
        $first = (int) $m['first'];
        if ($this->competingTail(substr($fragment, strlen($m[0])))) {
            return null;
        }
        $second = isset($m['second']) && $m['second'] !== '' ? (int) $m['second'] : null;
        if ($first <= 0 || ($second !== null && $second < $first)) {
            return null;
        }
        $unit = preg_match('/თვ|month/iu', $m['unit']) ? 'თვე' : 'წელი';
        $label = $first.($second !== null ? '–'.$second : '').(! empty($m['plus']) ? '+' : '').' '.$unit;

        return ['label' => $label, 'measurement' => ['text' => $label, 'unit' => 'recommended_age', 'source_unit' => $unit]];
    }

    protected function pressForce(string $fragment): ?array
    {
        if (! preg_match('/\A\s*([0-9]{1,4}(?:[.,][0-9]{1,2})?)\s*(?:ტონა|ton(?:ne)?s?)(?!\p{L})/iu', $fragment, $m) || (float) str_replace(',', '.', $m[1]) <= 0) {
            return null;
        }
        if ($this->competingTail(substr($fragment, strlen($m[0])))) {
            return null;
        }
        $label = $this->number((float) str_replace(',', '.', $m[1])).' ტონა';

        return ['label' => $label, 'measurement' => ['text' => $label, 'unit' => 'press_force', 'source_unit' => 'source_ton']];
    }

    protected function modelConflict(string $text, string $name): bool
    {
        preg_match_all('/(?:მოდელი|model)\s*[:：]\s*([A-Z]{1,6}[- ]?[0-9]{2,7}[A-Z0-9.-]*)/iu', $text, $models);
        $models = array_unique(array_map(fn ($x) => strtoupper(preg_replace('/[^A-Z0-9]/i', '', $x)), $models[1]));
        if (count($models) > 1) {
            return true;
        }
        foreach ($models as $model) {
            preg_match('/\A[A-Z]+/', $model, $prefix);
            if (! isset($prefix[0])) {
                continue;
            }
            preg_match_all('/\b'.preg_quote($prefix[0], '/').'[- ]?[0-9]{2,7}[A-Z0-9.-]*\b/iu', $name, $names);
            foreach ($names[0] as $named) {
                if (strtoupper(preg_replace('/[^A-Z0-9]/i', '', $named)) !== $model) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function competingTail(string $tail): bool
    {
        return (bool) preg_match('/\A\h*(?:[+×x\/]|[-–—]\h*[0-9]|[0-9]|[,;]\h*(?:[0-9]|or\b|ან\b)|(?:or|ან)\h+[0-9]|\(\h*(?:(?:or|ან)\h+)?[0-9])/iu', $tail);
    }

    protected function excludedContext(string $text, int $offset): bool
    {
        if ($this->blockedSection($text, $offset)) {
            return true;
        }
        $prefix = mb_substr(substr($text, 0, $offset), -90);

        return (bool) preg_match('/(?:შეფუთვის|ყუთის|კარტონის|შიდა|სამაცივრე კამერის|მაცივრის კამერის|მაცივრის განყოფილების|სამაცივრე განყოფილების|საყინულე კამერის|საყინულის კამერის|საყინულის განყოფილების|საყინულის|დამტენის|ადაპტერის|აქსესუარის|სადგამის|package|packaging|carton|shipping|inner|internal|freezer(?: compartment)?|refrigerator compartment|fridge compartment|cooling chamber|charger|adapter|accessory|stand)\s*\z/iu', $prefix);
    }

    /** Another locale without this property is sparse data, not a contradictory translation. */
    protected function hasRelevantClaim(string $kind, string $html, array $rule, string $name): bool
    {
        $text = $this->visible($html);
        if ($text === '') {
            return false;
        }
        if ($this->modelConflict($text, $name)) {
            return true;
        }
        if ($kind === 'area' && $this->qualifiedArea($text)['present']) {
            return true;
        }
        $headers = $kind === 'furniture_dimensions' ? ['ზომები', 'dimensions'] : ($rule['headers'] ?? []);
        if ($headers === []) {
            return false;
        }
        usort($headers, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '~(?<!\p{L})(?:'.implode('|', array_map(fn ($header) => preg_quote($header, '~'), $headers)).')\h*(?:\([^():\n]{1,20}\))?\h*[:：]~iu';
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$header, $offset]) {
            if (! $this->excludedContext($text, $offset)) {
                return true;
            }
        }

        return false;
    }

    protected function blockedSection(string $text, int $offset): bool
    {
        $prefix = substr($text, 0, $offset);
        preg_match_all('/(?:ზომები|მახასიათებლები|მონაცემები|პარამეტრები|dimensions|specifications|parameters)\s*[:：]/iu', $prefix, $sections, PREG_OFFSET_CAPTURE);
        if ($sections[0] === []) {
            return false;
        }
        $last = end($sections[0]);
        $subject = mb_substr(substr($prefix, 0, $last[1]), -65);

        return (bool) preg_match('/(?:შეფუთვის|ყუთის|კარტონის|შიდა|სამაცივრე კამერის|მაცივრის კამერის|საყინულის განყოფილების|საყინულის კამერის|აქსესუარის|დამტენის|ადაპტერის|სკამის|მაგიდის|package|carton|box|inner|internal|freezer compartment|refrigerator compartment|accessory|charger)\s*\z/iu', $subject);
    }

    protected function number(float $number): string
    {
        return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }

    protected function visible(string $html): string
    {
        if (strlen($html) > 200000 || trim($html) === '') {
            return '';
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($dom);
            foreach ($xpath->query('//script|//style|//template|//noscript|//*[@hidden or @aria-hidden="true"]') as $node) {
                $node->parentNode?->removeChild($node);
            }
            foreach ($xpath->query('//*[@style]') as $node) {
                if (preg_match('/(?:display\s*:\s*none|visibility\s*:\s*hidden)/i', $node->getAttribute('style'))) {
                    $node->parentNode?->removeChild($node);
                }
            }
            $visible = preg_replace('~</(?:p|div|li|tr|h[1-6])>|<br\s*/?>~i', "\n", $dom->saveHTML());
            $visible = html_entity_decode(strip_tags($visible), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return trim((string) preg_replace('/[^\S\n]+/u', ' ', $visible));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
