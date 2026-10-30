<?php

declare(strict_types=1);

namespace App\Services\Social;

use GdImage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Draws catalog facts only. Selection, image resolution and publishing live elsewhere. */
final class PostArtwork
{
    public const WIDTH = 1080;

    public const HEIGHT = 1350;

    public const STORY_HEIGHT = 1920;

    public const LINK_WIDTH = 1200;

    public const LINK_HEIGHT = 630;

    private string $bold;

    private string $regular;

    private array $palette;

    private array $options = [];

    private array $layout;

    public function render(array $snapshot, string $theme, string $destination, array $options = [], string $format = 'feed'): void
    {
        $this->options = [];
        $this->options = $this->validatedOptions($options);
        $this->layout = match ($format) {
            'link' => ['height' => self::LINK_HEIGHT],
            'feed' => ['height' => self::HEIGHT, 'margin' => 34, 'header_y' => 32, 'mark' => 62,
                'title_y' => 154, 'title_size' => 40, 'title_line' => 50, 'card_bottom' => 960,
                'old_y' => 1016, 'price_y' => 1174, 'price_size' => 122, 'badge_y' => 979,
                'monthly_y' => 1068, 'footer_y' => 1221, 'footer_height' => 84, 'line_y' => 1200],
            'story' => ['height' => self::STORY_HEIGHT, 'margin' => 54, 'header_y' => 178, 'mark' => 76,
                'title_y' => 345, 'title_size' => 43, 'title_line' => 56, 'card_bottom' => 1296,
                'old_y' => 1364, 'price_y' => 1526, 'price_size' => 138, 'badge_y' => 1320,
                'monthly_y' => 1398, 'footer_y' => 1616, 'footer_height' => 82, 'line_y' => 1565],
            default => throw new InvalidArgumentException('Unknown social artwork format.'),
        };
        if (! extension_loaded('gd') || ! function_exists('imagettftext')) {
            throw new RuntimeException('Social artwork requires PHP GD with FreeType support.');
        }

        $this->palette = match ($theme) {
            'black_friday' => ['background' => '#171A1A', 'ink' => '#FFFFFF', 'muted' => '#B3BEBA', 'accent' => '#FF751F', 'accent_ink' => '#191C19', 'badge' => '#DFFF8A', 'badge_ink' => '#191C19', 'line' => '#39413C', 'decoration' => '#272F29'],
            'new_year' => ['background' => '#103D31', 'ink' => '#FFFAEA', 'muted' => '#B9CEC3', 'accent' => '#E8C571', 'accent_ink' => '#173B2D', 'badge' => '#E8C571', 'badge_ink' => '#173B2D', 'line' => '#3F6453', 'decoration' => '#194B3B'],
            'evergreen' => ['background' => '#F8F5EC', 'ink' => '#16181C', 'muted' => '#858D98', 'accent' => '#FF6900', 'accent_ink' => '#FFFFFF', 'badge' => '#16181C', 'badge_ink' => '#FFFFFF', 'line' => '#E8EAEE', 'decoration' => '#F6F7F9'],
            default => throw new InvalidArgumentException('Unknown social artwork theme.'),
        };
        if (isset($this->options['accent_color'])) {
            $this->palette['accent'] = $this->options['accent_color'];
            $this->palette['accent_ink'] = $theme === 'evergreen' && $this->options['accent_color'] === '#FF6900'
                ? '#FFFFFF' : $this->contrastInk($this->options['accent_color']);
        }
        $brand = $theme === 'evergreen';
        $brandAccent = $this->palette['accent'] === '#FF6900'
            || $this->contrastInk($this->palette['accent']) === '#FFFFFF'
                ? $this->palette['accent'] : $this->palette['ink'];
        $this->palette += [
            'price' => $brand ? $brandAccent : $this->palette['ink'],
            'season_background' => $brand ? $this->softAccent($this->palette['accent']) : $this->palette['decoration'],
            'season_label' => $brand ? $brandAccent : $this->palette['accent'],
            'monthly_background' => $brand ? '#EFFAF3' : $this->palette['decoration'],
            'monthly_label' => $brand ? '#21945F' : $this->palette['muted'],
            'card_shadow' => $brand ? '#ECEEF2' : '#0A241C',
        ];

        $this->bold = resource_path('dashboard/fonts/Linotype-HelveticaNeueLTGEO75Bold.ttf');
        $this->regular = resource_path('dashboard/fonts/Linotype-HelveticaNeueLTGEO55Roman.ttf');
        foreach ([$this->bold, $this->regular] as $font) {
            if (! is_file($font) || @imagettfbbox(18, 0, $font, 'ა') === false) {
                throw new RuntimeException('The Georgian artwork fonts are missing or unreadable.');
            }
        }

        $title = $this->cleanText((string) ($snapshot['title'] ?? ''));
        $price = $this->amount($snapshot['price'] ?? null, true);
        $oldPrice = $this->amount($snapshot['old_price'] ?? null);
        $hasDiscount = $oldPrice !== null && $oldPrice > $price;
        $discount = $hasDiscount ? (int) round((1 - $price / $oldPrice) * 100) : 0;
        $monthly = $this->amount($snapshot['monthly_price'] ?? null);
        $months = filter_var($snapshot['installment_months'] ?? 24, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
        if ($title === '' || mb_strlen($title) > 2000 || ! $months) {
            throw new InvalidArgumentException('The artwork snapshot has invalid product text or installment terms.');
        }

        $source = $this->openImage((string) ($snapshot['image_path'] ?? ''));
        $canvas = imagecreatetruecolor($format === 'link' ? self::LINK_WIDTH : self::WIDTH, $this->layout['height']);
        if (! $canvas) {
            imagedestroy($source);
            throw new RuntimeException('Unable to allocate the social artwork canvas.');
        }

        try {
            imageantialias($canvas, true);
            imagefill($canvas, 0, 0, $this->color($canvas, 'background'));
            if ($format === 'link') {
                $this->linkCard($canvas, $source, $title, $price, $hasDiscount ? $oldPrice : null,
                    $monthly, (int) $months, $discount, $theme, (string) ($snapshot['url'] ?? ''));
                $this->save($canvas, $destination);

                return;
            }
            $this->decorations($canvas, $theme);
            $this->header($canvas, $theme);
            $this->title($canvas, $title);
            $this->productCard($canvas, $source);
            if ($discount > 0) {
                $this->discountBadge($canvas, $discount);
            }
            $this->price($canvas, $price, $hasDiscount ? $oldPrice : null, $monthly, (int) $months);
            $this->footer($canvas, (string) ($snapshot['url'] ?? ''));
            $this->save($canvas, $destination);
        } finally {
            imagedestroy($source);
            imagedestroy($canvas);
        }
    }

    /** Facebook link previews crop portrait images; keep every fact inside the native wide card. */
    private function linkCard(GdImage $canvas, GdImage $source, string $title, float $price, ?float $oldPrice,
        ?float $monthly, int $months, int $discount, string $theme, string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || ! preg_match('/\A[a-z0-9.-]+\z/iD', $host)) {
            throw new InvalidArgumentException('The snapshot must contain a valid product URL.');
        }
        $host = preg_replace('/\Awww\./i', '', $host);
        $this->roundRect($canvas, 32, 30, 54, 54, 14, '#FFFFFF');
        $logo = $this->openImage(public_path('img/logo.png'));
        try {
            imagecopyresampled($canvas, $logo, 38, 36, 0, 0, 42, 42, imagesx($logo), imagesy($logo));
        } finally {
            imagedestroy($logo);
        }
        $this->text($canvas, 'IAPI.GE', 27, 99, 62, 'ink');
        $this->text($canvas, $this->copyLines($this->label('tagline'), 11, 390, 1, false)[0], 11, 100, 83, 'muted', false);
        $season = $this->options['headline'] ?? $this->label('season.'.$theme);
        $this->text($canvas, $this->copyLines($season, 15, 480, 1)[0], 15, 33, 119, $theme === 'evergreen' ? 'season_label' : 'accent');
        $size = 34;
        while ($size > 28 && count($this->wrap($title, $size, 486)) > 3) {
            $size--;
        }
        foreach ($this->copyLines($title, $size, 486, 3) as $index => $line) {
            $this->text($canvas, $line, $size, 31, 169 + $index * 42, 'ink');
        }
        $this->roundRect($canvas, 33, 273, 58, 5, 2, 'accent');
        if ($oldPrice !== null) {
            $old = money($oldPrice);
            $oldSize = 24;
            while ($oldSize > 14 && $this->width($old, $oldSize, false) > 305) {
                $oldSize--;
            }
            $this->text($canvas, $old, $oldSize, 33, 337, 'muted', false);
            imagesetthickness($canvas, 2);
            imageline($canvas, 33, 328, 33 + $this->width($old, $oldSize, false), 328, $this->color($canvas, 'muted'));
        }
        if ($discount > 0) {
            $badge = '−'.$discount.'%';
            $this->roundRect($canvas, 373, 298, 139, 51, 16, 'badge');
            $this->centerText($canvas, $badge, 26, 443, 334, 'badge_ink');
        }
        $priceText = money($price);
        $this->text($canvas, $priceText, $this->fitSize($priceText, 492, 83, 43), 27, 437, 'price');
        if ($monthly !== null) {
            $this->roundRect($canvas, 32, 465, 480, 77, 18, 'monthly_background');
            $this->text($canvas, $this->label('installment').' · '.$months.' '.$this->label('months'), 15, 49, 491, 'monthly_label', false);
            $amount = money($monthly);
            $this->text($canvas, $amount, $this->fitSize($amount, 280, 26, 19), 49, 524, 'ink');
            $this->text($canvas, $this->label('monthly_suffix'), 14, 402, 524, 'monthly_label', false);
        }
        $cta = $this->label('cta');
        $this->text($canvas, $this->copyLines($cta, 19, 350, 1)[0], 19, 33, 593, 'accent');
        $this->text($canvas, $host, $this->fitSize($host, 150, 20, 12), 370, 593, 'ink');

        $this->roundRect($canvas, 550, 40, 618, 558, 24, 'card_shadow');
        $this->roundRect($canvas, 550, 32, 618, 558, 24, '#FFFFFF');
        [$sourceX, $sourceY, $width, $height] = $this->productBounds($source);
        $scale = min(574 / $width, 514 / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        imagecopyresampled($canvas, $source, 859 - (int) round($targetWidth / 2), 311 - (int) round($targetHeight / 2),
            $sourceX, $sourceY, $targetWidth, $targetHeight, $width, $height);
    }

    private function header(GdImage $canvas, string $theme): void
    {
        $margin = $this->layout['margin'];
        $top = $this->layout['header_y'];
        $mark = $this->layout['mark'];
        $story = $this->layout['height'] === self::STORY_HEIGHT;
        $this->roundRect($canvas, $margin, $top, $mark, $mark, 16, '#FFFFFF');
        $logo = $this->openImage(public_path('img/logo.png'));
        try {
            imagecopyresampled($canvas, $logo, $margin + 7, $top + 7, 0, 0, $mark - 14, $mark - 14, imagesx($logo), imagesy($logo));
        } finally {
            imagedestroy($logo);
        }
        $brandX = $margin + $mark + 15;
        $this->text($canvas, 'IAPI.GE', $story ? 33 : 29, $brandX, $top + (int) round($mark * 0.62), 'ink');
        $tagline = $this->label('tagline');
        $taglineSize = $story ? 14 : 12;
        if ($this->width($tagline, $taglineSize, false) > 375) {
            foreach ($this->copyLines($tagline, 11, 375, 2, false) as $index => $line) {
                $this->text($canvas, $line, 11, $brandX, $top + $mark - 3 + $index * 16, 'muted', false);
            }
        } else {
            $this->text($canvas, $tagline, $taglineSize, $brandX, $top + $mark, 'muted', false);
        }

        $label = $this->options['headline'] ?? $this->label('season.'.$theme);
        $size = $this->fitSize($label, 400, 20, 15);
        $this->roundRect($canvas, 564, $top + 6, 1080 - $margin - 564, $mark - 6, 24, 'season_background');
        $labelColor = 'season_label';
        $labelCenter = (int) round((564 + 1080 - $margin) / 2);
        if ($this->width($label, $size) > 400) {
            foreach ($this->copyLines($label, 15, 400, 2) as $index => $line) {
                $this->centerText($canvas, $line, 15, $labelCenter, $top + 26 + $index * 23, $labelColor);
            }
        } else {
            $this->centerText($canvas, $label, $size, $labelCenter, $top + (int) round($mark * 0.67), $labelColor);
        }
    }

    private function title(GdImage $canvas, string $title): void
    {
        $size = $this->layout['title_size'];
        $width = self::WIDTH - $this->layout['margin'] * 2;
        do {
            $lines = $this->wrap($title, $size, $width);
            if (count($lines) <= 3) {
                break;
            }
            $size -= 1;
        } while ($size > 30);

        if (count($lines) > 3) {
            $lines = array_slice($lines, 0, 3);
            $last = array_pop($lines);
            while ($last !== '' && $this->width($last.'…', $size) > $width) {
                $last = mb_substr($last, 0, -1);
            }
            $lines[] = rtrim($last, ' -,').'…';
        }

        $top = $this->layout['title_y'];
        foreach ($lines as $index => $line) {
            $this->text($canvas, $line, $size, $this->layout['margin'], $top + $index * $this->layout['title_line'], 'ink');
        }
        $this->layout['card_y'] = $top + (count($lines) - 1) * $this->layout['title_line'] + 34;
        $this->roundRect($canvas, $this->layout['margin'], $this->layout['card_y'] - 18, 56, 4, 2, 'accent');
    }

    private function productCard(GdImage $canvas, GdImage $source): void
    {
        $margin = $this->layout['margin'];
        $top = $this->layout['card_y'];
        $cardWidth = self::WIDTH - $margin * 2;
        $cardHeight = $this->layout['card_bottom'] - $top;
        $this->roundRect($canvas, $margin, $top + 8, $cardWidth, $cardHeight, 28, 'card_shadow');
        $this->roundRect($canvas, $margin, $top, $cardWidth, $cardHeight, 28, '#FFFFFF');
        [$sourceX, $sourceY, $width, $height] = $this->productBounds($source);
        $scale = min(($cardWidth - 36) / $width, ($cardHeight - 36) / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        imagecopyresampled($canvas, $source, 540 - (int) round($targetWidth / 2), $top + (int) round(($cardHeight - $targetHeight) / 2), $sourceX, $sourceY, $targetWidth, $targetHeight, $width, $height);
    }

    private function productBounds(GdImage $source): array
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $isBackground = static function (int $x, int $y) use ($source): bool {
            $pixel = imagecolorsforindex($source, imagecolorat($source, $x, $y));

            return $pixel['alpha'] > 117 || ($pixel['red'] >= 250 && $pixel['green'] >= 250 && $pixel['blue'] >= 250);
        };
        foreach ([[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]] as [$x, $y]) {
            if (! $isBackground($x, $y)) {
                return [0, 0, $width, $height];
            }
        }

        // Supplier photos often contain large empty margins. Trim only plain white/transparent padding.
        $step = max(1, (int) floor(max($width, $height) / 800));
        $left = $width;
        $top = $height;
        $right = 0;
        $bottom = 0;
        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                if (! $isBackground($x, $y)) {
                    $left = min($left, $x);
                    $top = min($top, $y);
                    $right = max($right, $x);
                    $bottom = max($bottom, $y);
                }
            }
        }
        if ($right <= $left || $bottom <= $top) {
            return [0, 0, $width, $height];
        }
        $padding = max(8, $step * 2, (int) round(max($right - $left, $bottom - $top) * 0.025));
        $left = max(0, $left - $padding);
        $top = max(0, $top - $padding);
        $right = min($width, $right + $padding);
        $bottom = min($height, $bottom + $padding);

        return [$left, $top, max(1, $right - $left), max(1, $bottom - $top)];
    }

    private function discountBadge(GdImage $canvas, int $discount): void
    {
        $width = 194;
        $left = self::WIDTH - $this->layout['margin'] - $width;
        $top = $this->layout['badge_y'];
        $this->roundRect($canvas, $left, $top, $width, 68, 22, 'badge');
        $ink = $this->palette['badge_ink'];
        $center = $left + (int) round($width / 2);
        $this->centerText($canvas, '−'.$discount.'%', 29, $center, $top + 36, $ink);
        $this->centerText($canvas, $this->label('discount'), 10, $center, $top + 56, $ink);
    }

    private function price(GdImage $canvas, float $price, ?float $oldPrice, ?float $monthly, int $months): void
    {
        if ($oldPrice !== null) {
            $oldText = money($oldPrice);
            $this->text($canvas, $oldText, 26, $this->layout['margin'] + 3, $this->layout['old_y'], 'muted', false);
            imagesetthickness($canvas, 2);
            imageline($canvas, $this->layout['margin'] + 3, $this->layout['old_y'] - 12, $this->layout['margin'] + 3 + $this->width($oldText, 26, false), $this->layout['old_y'] - 12, $this->color($canvas, 'muted'));
        }

        $priceText = money($price);
        $availableWidth = $monthly !== null ? 630 : self::WIDTH - $this->layout['margin'] * 2;
        $size = $this->fitSize($priceText, $availableWidth, $this->layout['price_size'], 48);
        $this->text($canvas, $priceText, $size, $this->layout['margin'] - 4, $this->layout['price_y'], 'price');

        if ($monthly !== null) {
            $left = 704;
            $top = $this->layout['monthly_y'];
            $height = $this->layout['height'] === self::STORY_HEIGHT ? 130 : 107;
            $this->roundRect($canvas, $left, $top, self::WIDTH - $this->layout['margin'] - $left, $height, 22, 'monthly_background');
            $this->text($canvas, $this->label('installment').' · '.$months.' '.$this->label('months'), 13, $left + 19, $top + 27, 'monthly_label', false);
            $monthlyText = money($monthly);
            $monthlySize = $this->fitSize($monthlyText, 202, 34, 23);
            $this->text($canvas, $monthlyText, $monthlySize, $left + 18, $top + $height - 20, 'ink');
            $this->text($canvas, $this->label('monthly_suffix'), 12, $left + 242, $top + $height - 20, 'monthly_label', false);
        }
    }

    private function footer(GdImage $canvas, string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || ! preg_match('/\A[a-z0-9.-]+\z/iD', $host)) {
            throw new InvalidArgumentException('The snapshot must contain a valid product URL.');
        }
        $host = preg_replace('/\Awww\./i', '', $host);
        $margin = $this->layout['margin'];
        $top = $this->layout['footer_y'];
        $height = $this->layout['footer_height'];
        $story = $this->layout['height'] === self::STORY_HEIGHT;
        $buttonWidth = $story ? self::WIDTH - $margin * 2 : 590;
        imagesetthickness($canvas, 1);
        imageline($canvas, $margin, $this->layout['line_y'], self::WIDTH - $margin, $this->layout['line_y'], $this->color($canvas, 'line'));
        $this->roundRect($canvas, $margin, $top, $buttonWidth, $height, 24, 'accent');
        $cta = $this->label('cta');
        $ctaSize = $story ? 28 : 26;
        $ctaWidth = $buttonWidth - 150;
        if ($this->width($cta, $ctaSize) > $ctaWidth) {
            $lines = $this->copyLines($cta, 20, $ctaWidth, 2);
            $baseline = count($lines) === 1 ? $top + 55 : $top + 31;
            foreach ($lines as $index => $line) {
                if ($story) {
                    $this->centerText($canvas, $line, 20, 540, $baseline + $index * 28, 'accent_ink');
                } else {
                    $this->text($canvas, $line, 20, $margin + 27, $baseline + $index * 28, 'accent_ink');
                }
            }
        } else {
            if ($story) {
                $this->centerText($canvas, $cta, $ctaSize, 540, $top + 55, 'accent_ink');
            } else {
                $this->text($canvas, $cta, $ctaSize, $margin + 27, $top + 55, 'accent_ink');
            }
        }
        imagesetthickness($canvas, 3);
        $arrow = $this->color($canvas, 'accent_ink');
        $arrowX = $margin + $buttonWidth - 38;
        $arrowY = $top + (int) round($height / 2);
        imageline($canvas, $arrowX - 35, $arrowY, $arrowX, $arrowY, $arrow);
        imageline($canvas, $arrowX - 13, $arrowY - 13, $arrowX, $arrowY, $arrow);
        imageline($canvas, $arrowX - 13, $arrowY + 13, $arrowX, $arrowY, $arrow);
        $hostSize = $this->fitSize($host, $story ? 800 : 350, $story ? 24 : 29, 18);
        if ($story) {
            $this->centerText($canvas, $host, $hostSize, 540, $top - 22, 'ink');
        } else {
            $this->text($canvas, $host, $hostSize, self::WIDTH - $margin - $this->width($host, $hostSize), $top + 56, 'ink');
        }
    }

    private function decorations(GdImage $canvas, string $theme): void
    {
        $color = $this->color($canvas, 'decoration');
        $anchor = $this->layout['height'] === self::STORY_HEIGHT ? 1450 : 1110;
        imagesetthickness($canvas, 20);
        imagearc($canvas, 1060, $anchor, 530, 530, 180, 360, $color);
        imagearc($canvas, 1110, $anchor, 652, 652, 180, 360, $color);
        if ($theme === 'new_year') {
            imagesetthickness($canvas, 1);
            foreach ([[476, $this->layout['header_y'] + 10, 6], [509, $this->layout['header_y'] + 23, 3]] as [$x, $y, $radius]) {
                $this->star($canvas, $x, $y, $radius, 'accent');
            }
        } elseif ($theme === 'black_friday') {
            imagesetthickness($canvas, 5);
            for ($index = 0; $index < 4; $index++) {
                imageline($canvas, 485 + $index * 15, $this->layout['header_y'] + 8, 463 + $index * 15, $this->layout['header_y'] + 31, $color);
            }
        }
    }

    private function star(GdImage $canvas, int $x, int $y, int $radius, string $color): void
    {
        imagefilledpolygon($canvas, [$x, $y - $radius, $x + 2, $y - 2, $x + $radius, $y, $x + 2, $y + 2, $x, $y + $radius, $x - 2, $y + 2, $x - $radius, $y, $x - 2, $y - 2], $this->color($canvas, $color));
    }

    private function openImage(string $path): GdImage
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~\A[a-z][a-z0-9+.-]*://~i', $path)) {
            throw new InvalidArgumentException('Artwork accepts local raster image files only.');
        }
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || filesize($resolved) > 25 * 1024 * 1024) {
            throw new InvalidArgumentException('The product image is unavailable or too large.');
        }
        $info = @getimagesize($resolved);
        if (! is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000 || $info[0] * $info[1] > 40000000) {
            throw new InvalidArgumentException('The product image has invalid or excessive dimensions.');
        }
        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($resolved),
            IMAGETYPE_PNG => @imagecreatefrompng($resolved),
            IMAGETYPE_GIF => @imagecreatefromgif($resolved),
            IMAGETYPE_WEBP => @imagecreatefromwebp($resolved),
            default => false,
        };
        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('The product image must be a readable JPEG, PNG, GIF or WebP.');
        }

        return $image;
    }

    private function amount(mixed $value, bool $required = false): ?float
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidArgumentException('The product price is required.');
            }

            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0 || (float) $value > 100000000) {
            throw new InvalidArgumentException('Artwork money amounts must be finite positive values.');
        }

        return (float) $value;
    }

    private function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
    }

    private function label(string $key): string
    {
        if (isset($this->options[$key])) {
            return $this->options[$key];
        }
        $translation = __('social.artwork.'.$key);
        if (! is_string($translation) || $translation === 'social.artwork.'.$key || $translation === '') {
            throw new RuntimeException('Missing social artwork translation: '.$key);
        }

        return $translation;
    }

    private function wrap(string $text, int $size, int $maxWidth, bool $bold = true): array
    {
        $lines = [];
        $current = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($this->width($candidate, $size, $bold) <= $maxWidth) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            foreach (mb_str_split($word) as $character) {
                if ($current !== '' && $this->width($current.$character, $size, $bold) > $maxWidth) {
                    $lines[] = $current;
                    $current = '';
                }
                $current .= $character;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function copyLines(string $text, int $size, int $width, int $maximum, bool $bold = true): array
    {
        $lines = $this->wrap($text, $size, $width, $bold);
        if (count($lines) <= $maximum) {
            return $lines;
        }
        $lines = array_slice($lines, 0, $maximum);
        $last = array_pop($lines);
        while ($last !== '' && $this->width($last.'…', $size, $bold) > $width) {
            $last = mb_substr($last, 0, -1);
        }
        $lines[] = rtrim($last).'…';

        return $lines;
    }

    private function validatedOptions(array $options): array
    {
        $limits = ['accent_color' => 7, 'headline' => 70, 'cta' => 28, 'tagline' => 45];
        if (array_diff(array_keys($options), array_keys($limits)) !== []) {
            throw new InvalidArgumentException('Unknown artwork option.');
        }
        $valid = [];
        foreach ($options as $key => $value) {
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}<>]/u', $value)) {
                throw new InvalidArgumentException('Artwork options must contain plain UTF-8 text.');
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value, 'UTF-8') > $limits[$key]
                || ($key === 'accent_color' && ! preg_match('/\A#[a-f0-9]{6}\z/iD', $value))) {
                throw new InvalidArgumentException('Invalid artwork option: '.$key);
            }
            $valid[$key] = $key === 'accent_color' ? strtoupper($value) : $value;
        }

        return $valid;
    }

    private function contrastInk(string $hex): string
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }
        $luminance = $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;

        return ($luminance + 0.05) / 0.05 >= 1.05 / ($luminance + 0.05) ? '#000000' : '#FFFFFF';
    }

    private function softAccent(string $hex): string
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $channels[] = (int) round(255 * 0.91 + hexdec(substr($hex, $offset, 2)) * 0.09);
        }

        return sprintf('#%02X%02X%02X', ...$channels);
    }

    private function fitSize(string $text, int $width, int $start, int $minimum): int
    {
        while ($start > $minimum && $this->width($text, $start) > $width) {
            $start--;
        }

        return $start;
    }

    private function width(string $text, int $size, bool $bold = true): int
    {
        $box = imagettfbbox($size, 0, $bold ? $this->bold : $this->regular, $text);
        if ($box === false) {
            throw new RuntimeException('Unable to measure artwork text.');
        }

        return (int) max($box[0], $box[2], $box[4], $box[6]) - (int) min($box[0], $box[2], $box[4], $box[6]);
    }

    private function text(GdImage $canvas, string $text, int $size, int $x, int $baseline, string $color, bool $bold = true): void
    {
        if (imagettftext($canvas, $size, 0, $x, $baseline, $this->color($canvas, $color), $bold ? $this->bold : $this->regular, $text) === false) {
            throw new RuntimeException('Unable to draw artwork text.');
        }
    }

    private function centerText(GdImage $canvas, string $text, int $size, int $center, int $baseline, string $color): void
    {
        $this->text($canvas, $text, $size, $center - (int) round($this->width($text, $size) / 2), $baseline, $color);
    }

    private function color(GdImage $canvas, string $color): int
    {
        $hex = ltrim($this->palette[$color] ?? $color, '#');

        return imagecolorallocate($canvas, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    private function roundRect(GdImage $canvas, int $x, int $y, int $width, int $height, int $radius, string $color): void
    {
        $ink = $this->color($canvas, $color);
        imagefilledrectangle($canvas, $x + $radius, $y, $x + $width - $radius, $y + $height, $ink);
        imagefilledrectangle($canvas, $x, $y + $radius, $x + $width, $y + $height - $radius, $ink);
        foreach ([[$x + $radius, $y + $radius], [$x + $width - $radius, $y + $radius], [$x + $radius, $y + $height - $radius], [$x + $width - $radius, $y + $height - $radius]] as [$cx, $cy]) {
            imagefilledellipse($canvas, $cx, $cy, $radius * 2, $radius * 2, $ink);
        }
    }

    private function save(GdImage $canvas, string $destination): void
    {
        $directory = realpath(dirname($destination));
        if ($directory === false || ! is_dir($directory) || strtolower(pathinfo($destination, PATHINFO_EXTENSION)) !== 'png') {
            throw new InvalidArgumentException('The artwork destination must be a PNG in an existing writable directory.');
        }
        $temporary = tempnam($directory, '.social-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to reserve the artwork destination.');
        }
        try {
            if (! imagepng($canvas, $temporary, 7) || ! rename($temporary, $destination)) {
                throw new RuntimeException('Unable to save the artwork PNG.');
            }
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
    }
}
