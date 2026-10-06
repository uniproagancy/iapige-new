<?php

namespace Tests\Unit;

use App\Services\Import\ImageDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A stored picture keeps the type it really is.
 *
 * Suppliers whose CDN refuses the shop's server are fetched through a worker,
 * and a proxied address carries the real one in its query rather than its path.
 * Reading only the path gave nothing, so every picture fetched that way was
 * saved as .jpg — and a PNG named .jpg is served with the wrong content type,
 * because the web server decides that from the extension.
 */
class ImageExtensionTest extends TestCase
{
    #[DataProvider('addresses')]
    public function test_it_reads_the_real_extension(string $url, string $expected): void
    {
        $method = new ReflectionMethod(ImageDownloader::class, 'extension');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(new ImageDownloader, $url));
    }

    public static function addresses(): array
    {
        $proxied = fn (string $url) => 'https://w.workers.dev?type=image&url='.urlencode($url).'&token=x';

        return [
            'direct png' => ['https://static.ee.ge/Elite/a_Thumb.png', 'png'],
            'direct jpeg' => ['https://static.ee.ge/Elite/a_Thumb.jpeg', 'jpeg'],
            'direct webp' => ['https://cdn.example/x.webp', 'webp'],
            'direct gif' => ['https://cdn.example/x.gif', 'gif'],

            // the case that was wrong: the type lives in the query
            'proxied png' => [$proxied('https://static.ee.ge/Elite/a_Thumb.png'), 'png'],
            'proxied jpeg' => [$proxied('https://static.ee.ge/Elite/a_Thumb.jpeg'), 'jpeg'],
            'proxied webp' => [$proxied('https://cdn.example/x.webp'), 'webp'],

            // nothing to go on either way, so the common case is assumed
            'no extension at all' => ['https://cdn.example/image', 'jpg'],
            'proxied with none' => [$proxied('https://cdn.example/image'), 'jpg'],
            'a query that is not a url' => ['https://cdn.example/image?w=200', 'jpg'],

            // an extension we do not serve is not a licence to invent one
            'unexpected extension' => ['https://cdn.example/x.svg', 'jpg'],
            'proxied unexpected' => [$proxied('https://cdn.example/x.bmp'), 'jpg'],
        ];
    }
}
