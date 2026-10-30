<?php

declare(strict_types=1);

$sourceRoot = realpath(getenv('IAPI_SOURCE_ROOT') ?: dirname(__DIR__, 2));
if (! $sourceRoot || ! is_file($sourceRoot.'/app/helpers.php')) {
    throw new RuntimeException('Set IAPI_SOURCE_ROOT to the IAPI application checkout.');
}
define('ARTWORK_SOURCE_ROOT', $sourceRoot);
require_once $sourceRoot.'/app/helpers.php';

if (! function_exists('resource_path')) {
    function resource_path(string $path = ''): string
    {
        return ARTWORK_SOURCE_ROOT.'/resources/'.ltrim($path, '/');
    }
}

if (! function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return ARTWORK_SOURCE_ROOT.'/public/'.ltrim($path, '/');
    }
}

if (! function_exists('__')) {
    function __(string $key): string
    {
        static $labels;
        if ($labels === null) {
            $path = ARTWORK_SOURCE_ROOT.'/lang/ka/social.php';
            $labels = is_file($path) ? require $path : require __DIR__.'/fixtures/artwork-labels.php';
        }
        $value = $labels;
        foreach (array_slice(explode('.', $key), 1) as $part) {
            $value = is_array($value) ? ($value[$part] ?? null) : null;
        }

        return is_string($value) ? $value : $key;
    }
}

$renderer = getenv('IAPI_ARTWORK_CLASS') ?: $sourceRoot.'/app/Services/Social/PostArtwork.php';
require_once $renderer;
