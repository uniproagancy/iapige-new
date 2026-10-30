<?php

declare(strict_types=1);

use App\Services\Social\PostArtwork;

$bootstrap = __DIR__.'/bootstrap-artwork.php';
if (! is_file($bootstrap)) {
    $bootstrap = rtrim(getenv('IAPI_SOURCE_ROOT') ?: dirname(__DIR__, 2), '/\\').'/tools/social-tests/bootstrap-artwork.php';
}
require $bootstrap;

$directory = (getenv('IAPI_ARTWORK_TMP') ?: sys_get_temp_dir()).'/iapi-artwork-'.bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function rejects(callable $action, string $message): void
{
    try {
        $action();
    } catch (InvalidArgumentException) {
        check(true, $message);

        return;
    }
    throw new RuntimeException($message);
}

function regionHash(string $path, int $left, int $top, int $width, int $height): string
{
    $image = imagecreatefrompng($path);
    $hash = hash_init('sha256');
    for ($y = $top; $y < $top + $height; $y++) {
        for ($x = $left; $x < $left + $width; $x++) {
            hash_update($hash, pack('N', imagecolorat($image, $x, $y)));
        }
    }
    imagedestroy($image);

    return hash_final($hash);
}

function countColor(string $path, int $target): int
{
    $image = imagecreatefrompng($path);
    $count = 0;
    for ($y = 1240; $y < 1290; $y++) {
        for ($x = 60; $x < 300; $x++) {
            $count += (imagecolorat($image, $x, $y) & 0xFFFFFF) === $target ? 1 : 0;
        }
    }
    imagedestroy($image);

    return $count;
}

function uniformRegion(string $path, int $top, int $height, int $color): bool
{
    $image = imagecreatefrompng($path);
    try {
        for ($y = $top; $y < $top + $height; $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                if ((imagecolorat($image, $x, $y) & 0xFFFFFF) !== $color) {
                    return false;
                }
            }
        }

        return true;
    } finally {
        imagedestroy($image);
    }
}

function regionColorCount(string $path, int $left, int $top, int $width, int $height, int $color): int
{
    $image = imagecreatefrompng($path);
    $count = 0;
    try {
        for ($y = $top; $y < $top + $height; $y++) {
            for ($x = $left; $x < $left + $width; $x++) {
                $count += (imagecolorat($image, $x, $y) & 0xFFFFFF) === $color ? 1 : 0;
            }
        }

        return $count;
    } finally {
        imagedestroy($image);
    }
}

function colorBounds(string $path, int $color): array
{
    $image = imagecreatefrompng($path);
    $left = imagesx($image);
    $right = -1;
    $top = imagesy($image);
    $bottom = -1;
    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            if ((imagecolorat($image, $x, $y) & 0xFFFFFF) === $color) {
                $left = min($left, $x);
                $right = max($right, $x);
                $top = min($top, $y);
                $bottom = max($bottom, $y);
            }
        }
    }
    imagedestroy($image);

    return ['left' => $left, 'top' => $top, 'width' => max(0, $right - $left + 1), 'height' => max(0, $bottom - $top + 1)];
}

try {
    $source = imagecreatetruecolor(800, 500);
    imagefill($source, 0, 0, imagecolorallocate($source, 255, 255, 255));
    imagefilledrectangle($source, 130, 50, 670, 450, imagecolorallocate($source, 75, 95, 105));
    imagepng($source, $directory.'/fixture.png');
    imagejpeg($source, $directory.'/fixture.jpg');
    imagegif($source, $directory.'/fixture.gif');
    imagewebp($source, $directory.'/fixture.webp');
    imagedestroy($source);
    $snapshot = ['product_id' => 1, 'title' => 'სატესტო პროდუქტი — ქართული ასოების და გრძელი დასახელების შემოწმება', 'price' => 1499, 'old_price' => 1899, 'monthly_price' => 63, 'installment_months' => 24, 'discount_percent' => 21, 'url' => 'https://iapi.ge/ka/product/test', 'image_path' => $directory.'/fixture.png'];
    $renderer = new PostArtwork;

    foreach (['black_friday', 'new_year', 'evergreen'] as $theme) {
        $path = $directory.'/'.$theme.'.png';
        $renderer->render($snapshot, $theme, $path);
        $info = getimagesize($path);
        check($info[0] === 1080 && $info[1] === 1350 && $info[2] === IMAGETYPE_PNG, 'Every theme must export a 1080×1350 PNG.');
        check(filesize($path) > 20000, 'The exported theme must contain rendered content.');
        $renderer->render($snapshot, $theme, $directory.'/repeat.png');
        check(hash_file('sha256', $path) === hash_file('sha256', $directory.'/repeat.png'), 'Identical snapshots and themes must produce identical pixels.');
    }
    $productBounds = colorBounds($directory.'/evergreen.png', 0x4B5F69);
    check($productBounds['width'] >= 700 && $productBounds['height'] >= 500, 'Feed products must occupy a substantially larger image area than the original 443 px photo box.');
    $renderer->render($snapshot, 'evergreen', $directory.'/explicit-feed.png', [], 'feed');
    check(hash_file('sha256', $directory.'/explicit-feed.png') === hash_file('sha256', $directory.'/evergreen.png'), 'The legacy four-argument API must keep selecting feed format.');

    $backgrounds = ['black_friday' => 0x171A1A, 'new_year' => 0x103D31, 'evergreen' => 0xF8F5EC];
    foreach ($backgrounds as $theme => $background) {
        $story = $directory.'/story-'.$theme.'.png';
        $renderer->render($snapshot, $theme, $story, [], 'story');
        $info = getimagesize($story);
        check($info[0] === 1080 && $info[1] === 1920 && $info[2] === IMAGETYPE_PNG, 'Every story theme must export a dedicated 1080×1920 PNG.');
        check(uniformRegion($story, 0, 160, $background), 'Story header content must stay below the top 160 px interface margin.');
        check(uniformRegion($story, 1700, 220, $background), 'Story prices and CTA must stay above the bottom 220 px interface margin.');
        $renderer->render($snapshot, $theme, $directory.'/story-repeat.png', [], 'story');
        check(hash_file('sha256', $story) === hash_file('sha256', $directory.'/story-repeat.png'), 'Story rendering must also be deterministic.');
        $renderer->render($snapshot, $theme, $directory.'/feed-after-story.png');
        check(hash_file('sha256', $directory.'/'.$theme.'.png') === hash_file('sha256', $directory.'/feed-after-story.png'), 'The larger story canvas must never leak into a later default feed render.');
    }

    $whiteProduct = imagecreatetruecolor(400, 600);
    imagefill($whiteProduct, 0, 0, imagecolorallocate($whiteProduct, 255, 255, 255));
    imagefilledrectangle($whiteProduct, 100, 50, 300, 550, imagecolorallocate($whiteProduct, 248, 248, 248));
    imagefilledrectangle($whiteProduct, 150, 150, 250, 250, imagecolorallocate($whiteProduct, 40, 40, 40));
    imagepng($whiteProduct, $directory.'/white-product.png');
    imagedestroy($whiteProduct);
    $renderer->render(array_replace($snapshot, ['title' => 'White product', 'image_path' => $directory.'/white-product.png']), 'evergreen', $directory.'/white-product-feed.png');
    check(colorBounds($directory.'/white-product-feed.png', 0xF8F8F8)['height'] >= 600, 'Trimming supplier padding must retain the outer silhouette of an almost-white product.');

    $options = ['accent_color' => '#3355aa', 'headline' => 'შენი ახალი შეთავაზება', 'cta' => 'აირჩიე ახლავე', 'tagline' => 'შენთვის შერჩეული ტექნიკა'];
    foreach ($options as $key => $value) {
        $renderer->render($snapshot, 'evergreen', $directory.'/option.png', [$key => $value]);
        check(hash_file('sha256', $directory.'/option.png') !== hash_file('sha256', $directory.'/evergreen.png'), 'Every supported overlay option must change the actual rendered image.');
    }
    foreach (['black_friday', 'new_year', 'evergreen'] as $theme) {
        $original = $directory.'/'.$theme.'.png';
        $custom = $directory.'/custom.png';
        $renderer->render($snapshot, $theme, $custom, $options);
        $image = imagecreatefrompng($custom);
        check((imagecolorat($image, 80, 1288) & 0xFFFFFF) === 0x3355AA, 'The chosen accent must appear in the exported image.');
        imagedestroy($image);
        check(regionHash($original, 852, 979, 194, 68) === regionHash($custom, 852, 979, 194, 68), 'Accent customization must preserve the theme discount badge and its legible text.');
        check(regionHash($original, 6, 180, 10, 400) === regionHash($custom, 6, 180, 10, 400), 'Accent customization must preserve the theme body background.');
        $renderer->render($snapshot, $theme, $directory.'/reset.png');
        check(hash_file('sha256', $original) === hash_file('sha256', $directory.'/reset.png'), 'A customized render must never leak options into the next default post.');
        $renderer->render($snapshot, $theme, $directory.'/blank.png', array_fill_keys(array_keys($options), ''));
        check(hash_file('sha256', $original) === hash_file('sha256', $directory.'/blank.png'), 'Blank overlay fields must preserve the exact default artwork.');
        $renderer->render($snapshot, $theme, $directory.'/custom-story.png', $options, 'story');
        check(hash_file('sha256', $directory.'/story-'.$theme.'.png') !== hash_file('sha256', $directory.'/custom-story.png'), 'Editable overlays must also change story artwork.');
        check(uniformRegion($directory.'/custom-story.png', 0, 160, $backgrounds[$theme]) && uniformRegion($directory.'/custom-story.png', 1700, 220, $backgrounds[$theme]), 'Custom story labels must remain within story interface margins.');
        $renderer->render($snapshot, $theme, $directory.'/story-reset.png', [], 'story');
        check(hash_file('sha256', $directory.'/story-'.$theme.'.png') === hash_file('sha256', $directory.'/story-reset.png'), 'Custom story styles must reset between posts.');
    }
    $renderer->render($snapshot, 'evergreen', $directory.'/dark-accent.png', ['accent_color' => '#000000', 'cta' => 'TEST']);
    check(countColor($directory.'/dark-accent.png', 0xFFFFFF) > 50, 'Dark custom CTA backgrounds must use legible white lettering.');
    $renderer->render($snapshot, 'evergreen', $directory.'/light-accent.png', ['accent_color' => '#FFFFFF', 'cta' => 'TEST']);
    check(countColor($directory.'/light-accent.png', 0x000000) > 50, 'Light custom CTA backgrounds must use legible black lettering.');
    check(regionColorCount($directory.'/light-accent.png', 25, 1020, 620, 160, 0x16181C) > 500, 'A light custom accent must not hide the actual price on the warm brand background.');
    foreach (['feed' => [25, 1020, 620, 160, 704, 1068, 342, 107],
        'story' => [45, 1370, 620, 170, 704, 1398, 322, 130],
        'link' => [25, 350, 490, 95, 32, 465, 480, 77]] as $format => $regions) {
        $brandPath = $directory.'/brand-'.$format.'.png';
        $renderer->render($snapshot, 'evergreen', $brandPath, ['accent_color' => '#FF6900'], $format);
        $brandImage = imagecreatefrompng($brandPath);
        check((imagecolorat($brandImage, 0, 0) & 0xFFFFFF) === 0xF8F5EC, 'Every default brand format must use the approved softly tinted warm background.');
        imagedestroy($brandImage);
        check(regionColorCount($brandPath, ...array_merge(array_slice($regions, 0, 4), [0xFF6900])) > 500, 'The main price must use the actual IAPI orange in every default format.');
        check(regionColorCount($brandPath, ...array_merge(array_slice($regions, 4, 4), [0xEFFAF3])) > 500, 'Green must remain a separate financing accent in every default format.');
    }
    check(regionColorCount($directory.'/brand-feed.png', 60, 1240, 380, 50, 0xFFFFFF) > 50, 'The IAPI-orange feed CTA must retain the site\'s white lettering.');
    check(regionColorCount($directory.'/brand-story.png', 250, 1640, 600, 50, 0xFFFFFF) > 50, 'The IAPI-orange Story CTA must retain the site\'s white lettering.');
    $renderer->render($snapshot, 'evergreen', $directory.'/maximum.png', ['headline' => str_repeat('W', 70), 'cta' => str_repeat('W', 28), 'tagline' => str_repeat('W', 45)]);
    check(is_file($directory.'/maximum.png'), 'Maximum length copy must fit safely within the overlay text areas.');
    $renderer->render($snapshot, 'evergreen', $directory.'/maximum-story.png', ['headline' => str_repeat('W', 70), 'cta' => str_repeat('W', 28), 'tagline' => str_repeat('W', 45)], 'story');
    check(uniformRegion($directory.'/maximum-story.png', 0, 160, $backgrounds['evergreen']) && uniformRegion($directory.'/maximum-story.png', 1700, 220, $backgrounds['evergreen']), 'Maximum length story copy must preserve both interface margins.');

    foreach ([['accent_color' => '#123'], ['accent_color' => '#12345G'], ['accent_color' => 'red'], ['headline' => str_repeat('ა', 71)],
        ['cta' => str_repeat('ა', 29)], ['tagline' => str_repeat('ა', 46)], ['headline' => "line\nbreak"], ['cta' => '<b>Buy</b>'],
        ['headline' => "\xFF"], ['headline' => "bad\u{202E}text"], ['tagline' => null], ['accent_color' => 123456], ['background_color' => '#000000']] as $invalidOptions) {
        rejects(fn () => $renderer->render($snapshot, 'evergreen', $directory.'/unsafe.png', $invalidOptions), 'Invalid color, text, control characters and unknown customization keys must fail closed.');
    }
    $renderer->render($snapshot, 'evergreen', $directory.'/after-invalid.png');
    check(hash_file('sha256', $directory.'/evergreen.png') === hash_file('sha256', $directory.'/after-invalid.png'), 'Rejected options must not alter later default rendering.');

    foreach (['png', 'jpg', 'gif', 'webp'] as $extension) {
        $renderer->render(array_replace($snapshot, ['image_path' => $directory.'/fixture.'.$extension]), 'evergreen', $directory.'/format.png');
        check(is_file($directory.'/format.png'), 'Raster format must render.');
    }

    $renderer->render(array_replace($snapshot, ['old_price' => null, 'monthly_price' => null, 'title' => str_repeat('ABCდასახელება', 45)]), 'black_friday', $directory.'/long.png');
    check(is_file($directory.'/long.png'), 'Long unbroken product titles must wrap and truncate without an error.');

    foreach (['https://example.com/product.png', 'php://filter/resource=fixture.png', $directory.'/missing.png'] as $path) {
        rejects(fn () => $renderer->render(array_replace($snapshot, ['image_path' => $path]), 'evergreen', $directory.'/unsafe.png'), 'Remote, stream and missing image paths must fail closed.');
    }
    file_put_contents($directory.'/fixture.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>');
    rejects(fn () => $renderer->render(array_replace($snapshot, ['image_path' => $directory.'/fixture.svg']), 'evergreen', $directory.'/unsafe.png'), 'SVG images must be rejected.');
    file_put_contents($directory.'/fake.png', 'this is not an image');
    rejects(fn () => $renderer->render(array_replace($snapshot, ['image_path' => $directory.'/fake.png']), 'evergreen', $directory.'/unsafe.png'), 'Renamed non-image content must be rejected.');
    foreach ([0, -1, INF, 'junk'] as $price) {
        rejects(fn () => $renderer->render(array_replace($snapshot, ['price' => $price]), 'evergreen', $directory.'/unsafe.png'), 'Invalid money amounts must fail closed.');
    }
    rejects(fn () => $renderer->render($snapshot, 'unknown', $directory.'/unsafe.png'), 'Unknown themes must be rejected.');
    rejects(fn () => $renderer->render($snapshot, 'evergreen', $directory.'/unsafe.png', [], 'reel'), 'Unknown canvas formats must be rejected.');
    rejects(fn () => $renderer->render(array_replace($snapshot, ['url' => 'invalid']), 'evergreen', $directory.'/unsafe.png'), 'Invalid destination URLs must fail closed.');
    rejects(fn () => $renderer->render($snapshot, 'evergreen', $directory.'/wrong.jpg'), 'Only PNG destinations may be written.');
    check(! is_file($directory.'/unsafe.png'), 'Rejected snapshots must never create output artwork.');

    echo $checks." artwork checks passed.\n";
} finally {
    foreach (glob($directory.'/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
}
