<?php

declare(strict_types=1);
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use SocialTests\LocalizationFacade;

$sourceRoot = realpath(getenv('IAPI_SOURCE_ROOT') ?: dirname(__DIR__, 2));
if ($sourceRoot === false || ! is_file($sourceRoot.'/app/helpers.php')) {
    throw new RuntimeException('Set IAPI_SOURCE_ROOT to a valid application root.');
}
$autoload = $sourceRoot.'/vendor/autoload.php';
if (! is_file($autoload) && ($testVendor = getenv('SOCIAL_TEST_VENDOR'))) {
    $autoload = rtrim($testVendor, '/\\').'/autoload.php';
}
if (! is_file($autoload)) {
    throw new RuntimeException('Install development dependencies or set SOCIAL_TEST_VENDOR to a compatible vendor directory.');
}
$loader = require $autoload;
define('SOCIAL_TEST_SOURCE_ROOT', $sourceRoot);
if (! defined('SOCIAL_MULTICHANNEL_PAYLOAD')) {
    define('SOCIAL_MULTICHANNEL_PAYLOAD', $sourceRoot);
}
$loader->setPsr4('App\\', [$sourceRoot.'/app']);
require_once $sourceRoot.'/app/helpers.php';
require __DIR__.'/TestCase.php';

if (! class_exists(LaravelLocalization::class)) {
    class_alias(LocalizationFacade::class, LaravelLocalization::class);
}
