<?php

declare(strict_types=1);

namespace App\Services\Feeds;

use App\Support\SsdCapacity;

class PrimaryFeatureFormatter
{
    public function format(string $kind, string $value, ?string $unit = null): string
    {
        $measurement = $this->measure($kind, $value, $unit);

        if ($measurement === null) {
            return '';
        }

        if (isset($measurement['text'])) {
            return $measurement['text'];
        }
        if (isset($measurement['display_unit'])) {
            $displayFactor = $measurement['display_factor'] ?? 1;
            $text = $this->number($measurement['low'] * $displayFactor);
            if ($measurement['high'] !== null && $measurement['high'] !== $measurement['low']) {
                $text .= '–'.$this->number($measurement['high'] * $displayFactor);
            }
            $suffix = $measurement['display_unit'];
            $qualifier = $measurement['qualifier'] ?? '';

            return ($qualifier === 'approx' ? 'დაახლ. ' : '').$text.' '.$suffix.($qualifier === 'max' ? '-მდე' : '');
        }

        $first = $kind === 'screen_cm' ? round($measurement['low']) : $measurement['low'];
        $second = $measurement['high'];

        if ($first <= 0) {
            return '';
        }

        $formatted = $this->number($first);

        if ($second !== null && ($measurement['separator'] === '/' || $second !== $first)) {
            $formatted .= $measurement['separator'].$this->number($second);
        }

        $suffix = match ($kind) {
            'screen_cm', 'height' => 'სმ',
            'area' => 'მ²',
            'volume' => 'ლ',
            'load' => 'კგ',
            'drying_load' => 'კგ',
            'cavity_volume' => 'ლ',
            'place_settings' => 'პერსონა',
            'cooking_zones' => 'ზონა',
            'burner_count' => 'კონფორა',
            'air_flow' => 'მ³/სთ',
            default => $measurement['unit'],
        };

        return ($kind === 'ssd_storage' ? 'SSD ' : '').$formatted.' '.$suffix
            .($kind === 'area' && ($measurement['qualifier'] ?? '') === 'max' ? '-მდე' : '');
    }

    public function measure(string $kind, string $value, ?string $unit = null): ?array
    {
        // A literal square-metre claim may qualify its supplied upper capacity.
        if ($kind === 'area' && preg_match('/\A(?<literal>.+?(?:მ[²2]|m[²2]))\s*(?:[-–—]\s*)?მდე\z/iu', trim($value), $maximum)) {
            $reading = $this->measure('area', $maximum['literal'], $unit);

            return $reading !== null ? [...$reading, 'qualifier' => 'max'] : null;
        }
        if ($kind === 'battery_voltage') {
            if (! preg_match('/\A\s*([0-9]+(?:[.,][0-9]+)?\s*V)\s*(?:[0-9]+(?:[.,][0-9]+)?\s*Ah)?\s*\.?\s*\z/iu', $value, $voltage)) {
                return null;
            }

            return $this->extra('voltage', $voltage[1], $unit);
        }
        if ($kind === 'laptop_fit') {
            $raw = trim($value);
            // This unit-bearing Georgian fit phrase states a maximum, not an installed screen.
            if (preg_match('/\A(?<size>[0-9]{1,9}(?:[.,][0-9]{1,3})?)\s+ინჩამდე\s+ლეპტოპი\z/u', $raw, $fit)) {
                $reading = $this->extra('compatible_screen', $fit['size'].' inch', $unit);

                return $reading !== null
                    ? ['text' => $this->format('compatible_screen', $fit['size'].' inch', $unit).'-მდე', 'unit' => $kind, 'source_unit' => $kind]
                    : null;
            }
            $maximum = (bool) preg_match('/\Aup\s+to\s+/iu', $raw);
            $raw = preg_replace('/\Aup\s+to\s+/iu', '', $raw);
            $reading = $this->extra('compatible_screen', $raw, $unit);
            if ($reading !== null) {
                $label = $this->format('compatible_screen', $raw, $unit).($maximum ? '-მდე' : '');

                return ['text' => $label, 'unit' => $kind, 'source_unit' => $kind];
            }
            $model = $this->extra('compatibility_model', $value, $unit);

            return $model !== null ? ['text' => $model['text'], 'unit' => $kind, 'source_unit' => $kind] : null;
        }
        if ($kind === 'rom_storage') {
            if (! preg_match('/\A\s*([0-9]+(?:[.,][0-9]+)?\s*(?:MB|GB|TB|მბ|გბ|ტბ))\s*(?:(?:UFS|eMMC)\s*[0-9]+(?:\.[0-9]+)?)?\s*\z/iu', $value, $storage)) {
                return null;
            }

            return $this->measure('storage', $storage[1], $unit);
        }
        if (in_array($kind, ['screen_inch', 'power', 'max_power', 'charge', 'energy', 'pressure', 'suction',
            'torque', 'runtime', 'flight_time', 'diameter', 'cable_length', 'rated_load', 'current',
            'voltage', 'steam_rate', 'megapixels', 'dpi', 'piece_count', 'core_count', 'fluid_capacity',
            'compatibility_model', 'platform', 'native_resolution', 'video_resolution', 'wifi_standard',
            'switch_type', 'printing_technology', 'socket', 'strap_size', 'audio_power', 'rms_power',
            'compatible_screen', 'switch_count', 'port_count', 'product_mass', 'pickup_pattern', 'cookware_diameter'], true)) {
            return $this->extra($kind, $value, $unit);
        }
        if ($kind === 'ssd_storage') {
            $capacity = SsdCapacity::normalize($value, $unit);

            return $capacity !== null ? $this->measure('storage', $capacity, $unit) : null;
        }

        if ($kind === 'cavity_volume') {
            $capacity = $this->statedCavityVolume($value, $unit);

            // A 0.2300 L source entry is not silently turned into a 23 L appliance.
            return $capacity !== null && $capacity['low'] >= 1 ? $capacity : null;
        }

        if ($kind === 'drying_load') {
            $capacity = $this->measure('load', $value, $unit);

            return $capacity !== null && $capacity['high'] === null ? $capacity : null;
        }

        if (in_array($kind, ['place_settings', 'cooking_zones'], true)) {
            return $this->count($kind, $value, $unit);
        }
        if ($kind === 'burner_count') {
            return $this->burnerCount($value, $unit);
        }

        $allowed = match ($kind) {
            'screen_cm' => ['inch', 'cm', 'm'],
            'storage' => ['MB', 'GB', 'TB'],
            'area' => ['m2'],
            'volume' => ['L'],
            'load' => ['kg'],
            'height' => ['cm', 'm'],
            'air_flow' => ['m3/h'],
            default => [],
        };

        if ($allowed === [] || mb_strlen($value) > 128 || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            return null;
        }

        $value = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $value));
        $number = '[0-9]{1,9}(?:[.,][0-9]{1,3})?';

        if (! preg_match('~\A(?<first>'.$number.')\s*(?<firstUnit>.*?)(?:\s*(?<separator>[-–—/])\s*(?<second>'.$number.')\s*(?<secondUnit>.*?))?\z~u', $value, $parts, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        $separator = $parts['separator'];

        if ($separator !== null && (($separator === '/' && $kind !== 'load')
            || ($separator !== '/' && ! in_array($kind, ['area', 'height', 'air_flow'], true)))) {
            return null;
        }

        $units = [];

        foreach ([$parts['firstUnit'], $parts['secondUnit'], $unit] as $candidate) {
            if ($candidate === null || trim($candidate) === '') {
                continue;
            }

            $canonical = $this->unit($candidate);

            if ($canonical === null || ! in_array($canonical, $allowed, true)) {
                return null;
            }

            $units[] = $canonical;
        }

        $units = array_values(array_unique($units));

        if (count($units) !== 1) {
            return null;
        }

        $sourceUnit = $units[0];
        $first = (float) str_replace(',', '.', $parts['first']);
        $second = $parts['second'] !== null ? (float) str_replace(',', '.', $parts['second']) : null;

        if ($first <= 0 || ($second !== null && ($second <= 0 || ($separator !== '/' && $second < $first)))) {
            return null;
        }

        $convert = fn (float $value): float => match ($sourceUnit) {
            'inch' => $value * 2.54,
            'm' => $value * 100,
            default => $value,
        };

        $first = $convert($first);
        $second = $second !== null ? $convert($second) : null;

        if ($first <= 0 || ($second !== null && $second <= 0)) {
            return null;
        }

        return [
            'low' => round($first, 5),
            'high' => $second !== null && ($separator === '/' || $second !== $first) ? round($second, 5) : null,
            'unit' => in_array($kind, ['screen_cm', 'height'], true) ? 'cm' : $sourceUnit,
            'source_unit' => $sourceUnit,
            'separator' => $separator === '/' ? '/' : '–',
        ];
    }

    protected function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    public function equivalent(string $kind, array $first, array $other): bool
    {
        if (isset($first['text']) || isset($other['text'])) {
            return isset($first['text'], $other['text']) && $first['unit'] === $other['unit']
                && mb_strtolower($first['text']) === mb_strtolower($other['text']);
        }
        if ($first['unit'] !== $other['unit'] || $first['high'] !== $other['high']
            || ($first['qualifier'] ?? '') !== ($other['qualifier'] ?? '')) {
            return false;
        }
        if (abs($first['low'] - $other['low']) < 0.00001) {
            return true;
        }
        if ($kind !== 'screen_cm' || $first['high'] !== null || $other['high'] !== null) {
            return false;
        }
        $inchAndCentimetres = ($first['source_unit'] === 'inch' && $other['source_unit'] === 'cm'
            && abs($other['low'] - round($other['low'])) < 0.00001)
            || ($other['source_unit'] === 'inch' && $first['source_unit'] === 'cm'
                && abs($first['low'] - round($first['low'])) < 0.00001);

        return $inchAndCentimetres && abs($first['low'] - $other['low']) < 1;
    }

    protected function extra(string $kind, string $raw, ?string $declared): ?array
    {
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (mb_strlen($raw) > 160 || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $raw)) {
            return null;
        }
        $raw = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $raw));
        if (in_array($kind, ['compatibility_model', 'platform', 'native_resolution', 'video_resolution',
            'wifi_standard', 'switch_type', 'printing_technology', 'socket', 'strap_size', 'pickup_pattern'], true)) {
            return $this->textProperty($kind, $raw, $declared);
        }
        // A supplier's 14,500 Pa may use grouping; do not quietly publish 14.5 Pa.
        if (preg_match('/(?<![0-9])[1-9][0-9]*,[0-9]{3}(?![0-9])/u', $raw)) {
            return null;
        }
        if ($kind === 'suction' && preg_match('/\A\s*([0-9]+(?:[.,][0-9]+)?\s*k?Pa)\s*[,\/]?\s*[0-9]+(?:[.,][0-9]+)?(?:\s*[-–—]\s*[0-9]+(?:[.,][0-9]+)?)?\s*AW\s*\z/iu', $raw, $pressure)) {
            return $this->extra('suction', $pressure[1], $declared);
        }
        $qualifier = in_array($kind, ['max_power', 'flight_time', 'rated_load'], true) ? 'max' : '';
        $rms = $kind === 'rms_power';
        if (in_array($kind, ['rms_power', 'audio_power'], true) && preg_match('/\s*RMS\s*\z/iu', $raw)) {
            $raw = preg_replace('/\s*RMS\s*\z/iu', '', $raw);
            $rms = true;
        }
        if (in_array($kind, ['runtime', 'flight_time', 'rated_load'], true)) {
            if (preg_match('/\A(?:up\s+to|max\.?|მაქს\.?|მდე)\s*/iu', $raw, $prefix)) {
                $qualifier = 'max';
                $raw = substr($raw, strlen($prefix[0]));
            } elseif (preg_match('/\A(?:approx\.?|approximately|დაახლოებით|~)\s*/iu', $raw, $prefix)) {
                $qualifier = 'approx';
                $raw = substr($raw, strlen($prefix[0]));
            }
            if (preg_match('/(?:მდე|-მდე)\z/u', $raw)) {
                if ($qualifier === 'approx') {
                    return null;
                }
                $qualifier = 'max';
                $raw = str_replace(['საათამდე', 'წუთამდე'], ['საათი', 'წუთი'], $raw);
                $raw = preg_replace('/-?მდე\z/u', '', $raw);
            }
        }
        $number = '[0-9]{1,9}(?:[.,][0-9]{1,3})?';
        if (! preg_match('~\A(?<first>'.$number.')\s*(?<firstUnit>.*?)(?:\s*[-–—]\s*(?<second>'.$number.')\s*(?<secondUnit>.*?))?\z~u', $raw, $parts, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $allowed = match ($kind) {
            'screen_inch', 'compatible_screen' => ['inch', 'cm'], 'power', 'max_power', 'audio_power', 'rms_power' => ['W', 'kW'],
            'charge' => ['mAh', 'Ah'], 'energy' => ['Wh', 'kWh'],
            'pressure' => ['bar', 'MPa', 'kPa', 'Pa'], 'suction' => ['Pa', 'kPa', 'AW', 'W'],
            'torque' => ['Nm'], 'runtime', 'flight_time' => ['h', 'min'],
            'diameter', 'cookware_diameter' => ['mm', 'cm'], 'cable_length' => ['cm', 'm'],
            'rated_load' => ['kg', 't'], 'product_mass' => ['kg', 'g'], 'current' => ['A'], 'voltage' => ['V'],
            'steam_rate' => ['g/min'], 'megapixels' => ['MP'], 'dpi' => ['DPI'],
            'piece_count' => ['pieces'], 'core_count' => ['cores'], 'switch_count' => ['switches'],
            'port_count' => ['ports'], 'fluid_capacity' => ['L', 'ml'],
            default => [],
        };
        $units = [];
        foreach ([$parts['firstUnit'], $parts['secondUnit'], $declared] as $candidate) {
            if ($candidate === null || trim($candidate) === '') {
                continue;
            }
            $parsed = $this->extraUnit($candidate);
            if ($parsed === null || ! in_array($parsed, $allowed, true)) {
                return null;
            }
            $units[] = $parsed;
        }
        $units = array_values(array_unique($units));
        if ($units === [] && in_array($kind, ['dpi', 'piece_count', 'core_count', 'switch_count', 'port_count'], true)) {
            $units = [$allowed[0]];
        }
        if (count($units) !== 1) {
            return null;
        }
        $source = $units[0];
        $low = (float) str_replace(',', '.', $parts['first']);
        $high = $parts['second'] !== null ? (float) str_replace(',', '.', $parts['second']) : null;
        if ($low <= 0 || ($high !== null && $high < $low)
            || (in_array($kind, ['screen_inch', 'megapixels', 'dpi', 'piece_count', 'core_count', 'switch_count', 'port_count'], true) && $high !== null)
            || (in_array($kind, ['dpi', 'piece_count', 'core_count', 'switch_count', 'port_count'], true) && floor($low) !== $low)) {
            return null;
        }
        $target = match ($kind) {
            'suction' => in_array($source, ['Pa', 'kPa'], true) ? 'Pa' : $source,
            'screen_inch', 'compatible_screen' => 'inch', 'power', 'max_power', 'audio_power', 'rms_power' => 'W', 'charge' => 'mAh', 'energy' => 'Wh',
            'diameter', 'cookware_diameter' => 'mm', 'cable_length' => 'm', 'rated_load', 'product_mass' => 'kg', 'fluid_capacity' => 'L',
            default => $source,
        };
        $factor = match (true) {
            $source === 'kPa' && $target === 'Pa' => 1000,
            $source === 'cm' && $target === 'inch' => 1 / 2.54,
            $source === 'kW' && $target === 'W', $source === 'Ah' && $target === 'mAh',
            $source === 'kWh' && $target === 'Wh', $source === 't' && $target === 'kg' => 1000,
            $source === 'cm' && $target === 'mm' => 10,
            $source === 'cm' && $target === 'm' => 0.01,
            $source === 'ml' && $target === 'L' => 0.001,
            $source === 'g' && $target === 'kg' => 0.001,
            default => 1,
        };
        $low *= $factor;
        $high = $high !== null ? $high * $factor : null;
        if (round($low, 3) <= 0 || ($high !== null && round($high, 3) <= 0)) {
            return null;
        }
        $display = match ($target) {
            'inch' => 'ინჩი', 'W' => 'ვტ', 'mAh' => 'mAh', 'Wh' => 'Wh',
            'mm' => 'მმ', 'm' => 'მ', 'kg' => 'კგ', 'L' => 'ლ',
            'min' => 'წთ', 'h' => 'სთ', 'Nm' => 'ნმ', 'bar' => 'ბარი',
            'g/min' => 'გ/წთ', 'MP' => 'MP', 'pieces' => 'ცალი', 'cores' => 'ბირთვი',
            'switches' => 'ჩამრთველი', 'ports' => 'პორტი',
            default => $target,
        };
        if ($rms) {
            $display .= ' RMS';
        }

        return ['low' => round($low, 5), 'high' => $high !== null && $high !== $low ? round($high, 5) : null,
            'unit' => $target, 'source_unit' => $source, 'separator' => '–', 'display_unit' => $kind === 'cookware_diameter' ? 'სმ' : $display,
            'display_factor' => $kind === 'cookware_diameter' ? 0.1 : 1, 'qualifier' => $qualifier];
    }

    protected function textProperty(string $kind, string $raw, ?string $unit): ?array
    {
        if ($raw === '' || mb_strlen($raw) > 90 || ($unit !== null && trim($unit) !== '')
            || preg_match('/\A(?:no|yes|არა|დიახ|n\/a|none|unknown|universal|უნივერსალური|[-–—])\z/iu', $raw)) {
            return null;
        }
        if ($kind === 'compatibility_model' && preg_match('/\A(?<first>[0-9]+(?:[.,][0-9]+)?)\s*["″]\s*[-–—]\s*(?<second>[0-9]+(?:[.,][0-9]+)?)\s*["″]\s*(?:ლეპტოპები და პლანშეტები|laptops and tablets)\z/iu', $raw, $fit)) {
            $sizes = $this->extra('compatible_screen', $fit['first'].'"–'.$fit['second'].'"', null);

            return $sizes !== null ? ['text' => $raw, 'unit' => $kind, 'source_unit' => $kind] : null;
        }
        $valid = match ($kind) {
            'compatibility_model' => ! preg_match('/\A(?:for\s+)?(?:apple|samsung|xiaomi|android|ios|ipados|windows|macos|mobile phone|phone|tablet|computer|მობილური|ტელეფონი|პლანშეტი)\z/iu', $raw)
                && (preg_match('/(?:airpods|airtag|apple (?:pencil|watch)|surface|galaxy|ipad(?!os)|iphone|redmi|macbook|imac|mac mini|dji|gopro|insta360|playstation|nintendo|xbox)/iu', $raw)
                    || preg_match('/\b(?:epson|canon|nikon|sony|brother|hp|dell|asus|acer|lenovo|huawei|honor|xiaomi|samsung|boox|kobo|polaroid|garmin)\s+(?!(?:windows|android|ios|ipados|macos)\b)[\p{L}\p{N}][^,;]*[0-9]/iu', $raw)),
            'platform' => preg_match('/\A(?:PS[345]|PlayStation\s*[345]|XBOX(?:\s+(?:One|Series\s*[XS](?:\s*\/\s*[XS])?|360))?|Nintendo\s+Switch(?:\s*2)?|PC|Windows)(?:\s*[,\/+&]\s*(?:PS[345]|PlayStation\s*[345]|XBOX(?:\s+(?:One|Series\s*[XS]))?|Nintendo\s+Switch(?:\s*2)?|PC|Windows))*\z/iu', $raw),
            'native_resolution', 'video_resolution' => preg_match('/\A(?:[0-9]{3,5}\s*[x×*]\s*[0-9]{3,5}(?:\s*(?:pixels|პიქსელი))?(?:\s*\([A-Z0-9 +.]+\))?|(?:[0-9](?:\.[0-9])?K|[0-9]{3,4}[pi]|FHD|HD|UHD|QHD|WXGA|WUXGA|XGA|Full HD)(?:\s*\([0-9]{3,5}\s*[x×*]\s*[0-9]{3,5}\))?(?:\s+[0-9]{1,3}\s*fps)?)\z/iu', $raw),
            'wifi_standard' => preg_match('/\A(?:IEEE\s*)?802\.11[\w\s,\/().-]+\z/iu', $raw)
                || preg_match('/\Awi[ -]?fi\s*[4-7](?:\s*\/\s*wi[ -]?fi\s*[4-7])*(?:\s*\(802\.11[a-z\/]+\))?\z/iu', $raw),
            'switch_type' => preg_match('/\A(?:[\p{L} -]{1,35})(?:switch|გადამრთველი)?\z/iu', $raw)
                && ! preg_match('/\A(?:yes|no|დიახ|არა|hot[ -]?swap)\z/iu', $raw),
            'printing_technology' => preg_match('/\A(?:inkjet|laser|thermal transfer|thermal|dot matrix|ჭავლური|ლაზერული|თერმული გადაცემა|თერმული|მელნის ჭავლური|EcoTank(?:\s*\([^()]{1,40}\))?|Epson Micro Piezo(?:\s*\(inkjet\))?)\z/iu', $raw),
            'socket' => preg_match('/\A(?:LGA\s*[0-9]{3,5}|AM[345](?:\+)?|FM[12](?:\+)?|sTR[0-9A-Z]{1,5})(?:\s*[,\/]\s*(?:LGA\s*[0-9]{3,5}|AM[345](?:\+)?|FM[12](?:\+)?))*\z/iu', $raw),
            'strap_size' => preg_match('/\A[0-9]{1,2}\s*mm(?:\s*[,\/]\s*[0-9]{1,2}\s*mm){0,5}\z/iu', $raw),
            'pickup_pattern' => preg_match('/\A(?:omnidirectional|cardioid|supercardioid|hypercardioid|bidirectional|figure[ -]?8|unidirectional|კარდიოიდული|ომნიდირექციული)\z/iu', $raw),
            default => false,
        };
        if (! $valid) {
            return null;
        }
        if ($kind === 'wifi_standard') {
            preg_match_all('/wi[ -]?fi\s*([4-7])/iu', $raw, $versions);
            $numbers = array_map('intval', $versions[1]);
            if (preg_match('/802\.11\s*([a-z]+(?:\s*[\/,]\s*[a-z]+)*)/iu', $raw, $standards)) {
                foreach (preg_split('/\s*[\/,]\s*/', mb_strtolower($standards[1])) as $standard) {
                    if (isset(['n' => 4, 'ac' => 5, 'ax' => 6][$standard])) {
                        $numbers[] = ['n' => 4, 'ac' => 5, 'ax' => 6][$standard];
                    }
                }
            }
            if ($numbers !== []) {
                $raw = 'Wi-Fi '.max($numbers);
            }
        }

        return ['text' => $raw, 'unit' => $kind, 'source_unit' => $kind];
    }

    protected function extraUnit(string $unit): ?string
    {
        $unit = mb_strtolower(trim((string) preg_replace('/[\s\p{Z}.]+/u', '', $unit)));

        return match ($unit) {
            'w', 'watt', 'watts', 'ვტ', 'ვატი' => 'W', 'kw', 'კვტ', 'კილოვატი' => 'kW',
            'mah', 'მა/სთ', 'მილიამპერსაათი' => 'mAh', 'ah', 'ა/სთ' => 'Ah',
            'wh', 'ვტსთ', 'ვტ/სთ' => 'Wh', 'kwh', 'კვტსთ', 'კვტ/სთ' => 'kWh',
            'bar', 'ბარი', 'ბარ' => 'bar', 'pa', 'პა' => 'Pa', 'kpa', 'კპა' => 'kPa', 'mpa' => 'MPa', 'aw' => 'AW',
            'nm', 'ნმ', 'n·m' => 'Nm', 'a', 'ამპერი' => 'A', 'v', 'ვ', 'ვოლტი' => 'V',
            'h', 'hr', 'hrs', 'hour', 'hours', 'სთ', 'საათი' => 'h',
            'min', 'mins', 'minute', 'minutes', 'წთ', 'წუთი' => 'min',
            'mm', 'მმ' => 'mm', 'cm', 'სმ' => 'cm', 'm', 'მ' => 'm',
            '"', '″', 'in', 'inch', 'inches', 'ინჩი', 'დიუმი', 'დუიმი' => 'inch',
            'kg', 'კგ', 'კილოგრამი' => 'kg', 'g', 'გ', 'გრ', 'გრამი' => 'g', 't', 'ტ', 'ტონა' => 't',
            'g/min', 'გ/წთ', 'გრ/წთ' => 'g/min', 'mp', 'მპ', 'მეგაპიქსელი' => 'MP',
            'dpi' => 'DPI', 'piece', 'pieces', 'pcs', 'ც', 'ცალი' => 'pieces',
            'core', 'cores', 'ბირთვი' => 'cores', 'l', 'ლ', 'ლიტრი' => 'L', 'ml', 'მლ' => 'ml',
            'switch', 'switches', 'ჩამრთველი' => 'switches', 'port', 'ports', 'პორტი' => 'ports',
            default => null,
        };
    }

    public function statedCavityVolume(string $value, ?string $unit = null): ?array
    {
        // Preserve source decimals such as 0.2300 L for validation; never multiply their quantity.
        if (mb_strlen($value) > 128 || ! preg_match('/\A\s*(?<number>[0-9]{1,9}(?:[.,][0-9]{1,9})?)\s*(?<unit>[^0-9]*)\z/u', $value, $parts)) {
            return null;
        }
        $measurement = $this->measure('volume', '1 '.$parts['unit'], $unit);
        $number = (float) str_replace(',', '.', $parts['number']);
        if ($measurement === null || $number <= 0) {
            return null;
        }
        $measurement['low'] = round($number, 5);

        return $measurement;
    }

    protected function count(string $kind, string $value, ?string $unit): ?array
    {
        if (mb_strlen($value) > 128 || ! preg_match('/\A\s*(?<number>[0-9]{1,9})\s*(?<unit>[^0-9]*)\z/u', $value, $parts)
            || (int) $parts['number'] <= 0) {
            return null;
        }
        $allowed = $kind === 'place_settings'
            ? ['პერსონა', 'პერსონაზე', 'პერსონები', 'კომპლექტი', 'კომპლექტები', 'person', 'persons', 'place', 'places', 'placesetting', 'placesettings', 'set', 'sets']
            : ['ზონა', 'ზონები', 'კომფორი', 'კონფორი', 'კომფორები', 'კონფორები', 'zone', 'zones', 'cookingzone', 'cookingzones', 'burner', 'burners'];
        foreach ([$parts['unit'], $unit] as $candidate) {
            $candidate = mb_strtolower(trim((string) preg_replace('/[\s\p{Z}]+/u', '', (string) $candidate)));
            if ($candidate !== '' && ! in_array($candidate, $allowed, true)) {
                return null;
            }
        }

        return ['low' => (float) $parts['number'], 'high' => null, 'unit' => $kind, 'source_unit' => $kind, 'separator' => '–'];
    }

    /** Only configured TOTAL fields may use this installed-burner count grammar. */
    protected function burnerCount(string $value, ?string $unit): ?array
    {
        $nouns = ['კონფორა', 'კონფორი', 'კონფორები', 'კომფორი', 'კომფორები', 'კანფორა', 'კანფორი', 'კანფორები', 'burner', 'burners', 'ცალი', 'pcs'];
        $declared = mb_strtolower(trim((string) $unit));
        if ($declared !== '' && ! in_array($declared, $nouns, true)) {
            return null;
        }
        $value = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $value));
        if (mb_strlen($value) > 128) {
            return null;
        }
        $gas = ['გაზი', 'გაზის', 'gas'];
        $electric = ['ელექტრო', 'ელექტრული', 'დენი', 'ინდუქციური', 'electric', 'induction'];
        $fuel = '(?:'.implode('|', array_map(fn ($x) => preg_quote($x, '~'), [...$gas, ...$electric])).')';
        $noun = '(?:'.implode('|', array_map(fn ($x) => preg_quote($x, '~'), $nouns)).')';
        $number = '[1-9][0-9]{0,8}';
        if (preg_match('~\A(?<number>'.$number.')(?:\s*(?:'.$fuel.'(?:\s*'.$noun.')?|'.$noun.'))?\z~iu', $value, $single)) {
            $count = (int) $single['number'];
        } elseif (preg_match('~\A(?<first>'.$number.')\s*(?<firstFuel>'.$fuel.')(?:\s*'.$noun.')?\s*[+,]\s*(?<second>'.$number.')\s*(?<secondFuel>'.$fuel.')(?:\s*'.$noun.')?\z~iu', $value, $mixed)) {
            $firstGas = in_array(mb_strtolower($mixed['firstFuel']), $gas, true);
            $secondGas = in_array(mb_strtolower($mixed['secondFuel']), $gas, true);
            if ($firstGas === $secondGas) {
                return null;
            }
            // Explicit disjoint gas/electric partitions in one TOTAL claim, never separate attributes.
            $count = (int) $mixed['first'] + (int) $mixed['second'];
        } else {
            return null;
        }

        return ['low' => (float) $count, 'high' => null, 'unit' => 'burner_count', 'source_unit' => 'burner_count', 'separator' => '–'];
    }

    protected function unit(string $unit): ?string
    {
        $unit = mb_strtolower(trim($unit));
        $words = preg_split('/[\s\p{Z}]+/u', $unit, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) > 1) {
            $same = array_map(fn (string $word) => $this->unit($word), $words);
            if (! in_array(null, $same, true) && count(array_unique($same)) === 1) {
                return $same[0];
            }
        }
        $unit = str_replace(['²', '^2', '³', '^3', '㎥', '″', '”', '“'], ['2', '2', '3', '3', 'm3', '"', '"', '"'], $unit);
        $unit = preg_replace('/[\s\p{Z}.]+/u', '', $unit);

        return match ($unit) {
            '"', 'in', 'inch', 'inches', 'ინჩი', 'ინჩიანი', 'დუიმი', 'დიუმი', 'დიუმიანი' => 'inch',
            'cm', 'centimeter', 'centimeters', 'centimetre', 'centimetres', 'სმ', 'სანტიმეტრი', 'სანტიმეტრები' => 'cm',
            'm', 'meter', 'meters', 'metre', 'metres', 'მ', 'მეტრი', 'მეტრები' => 'm',
            'mb', 'megabyte', 'megabytes', 'მბ', 'მეგაბაიტი', 'მეგაბაიტები' => 'MB',
            'gb', 'gigabyte', 'gigabytes', 'გბ', 'გიგაბაიტი', 'გიგაბაიტები' => 'GB',
            'tb', 'terabyte', 'terabytes', 'ტბ', 'ტერაბაიტი', 'ტერაბაიტები' => 'TB',
            'm2', 'მ2', 'sqm', 'squaremeter', 'squaremeters', 'squaremetre', 'squaremetres', 'კვმ', 'კვადრატულიმეტრი', 'კვადრატულიმეტრები' => 'm2',
            'l', 'liter', 'liters', 'litre', 'litres', 'ლ', 'ლიტრი', 'ლიტრები' => 'L',
            'kg', 'kilogram', 'kilograms', 'კგ', 'კილოგრამი', 'კილოგრამები' => 'kg',
            'm3/h', 'm3/hr', 'm3/hour', 'm3/სთ', 'მ3/სთ', 'მ3/h', 'm3სთ', 'მ3სთ' => 'm3/h',
            default => null,
        };
    }
}
