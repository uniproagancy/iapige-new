<?php

declare(strict_types=1);

$sourceRoot = realpath(getenv('IAPI_SOURCE_ROOT') ?: dirname(__DIR__, 2));
if ($sourceRoot === false || ! is_file($sourceRoot.'/app/helpers.php')) {
    throw new RuntimeException('The isolated Alta source root is invalid. Set IAPI_SOURCE_ROOT to the application root.');
}
$autoload = $sourceRoot.'/vendor/autoload.php';
if (! is_file($autoload) && ($testVendor = getenv('ALTA_TEST_VENDOR'))) {
    $autoload = rtrim($testVendor, '/\\').'/autoload.php';
}
if (! is_file($autoload)) {
    throw new RuntimeException('Install the project development dependencies or set ALTA_TEST_VENDOR to an existing compatible vendor directory.');
}
$loader = require $autoload;
define('ALTA_TEST_SOURCE_ROOT', $sourceRoot);
$loader->setPsr4('App\\', [$sourceRoot.'/app']);
require_once $sourceRoot.'/app/helpers.php';
require __DIR__.'/TestCase.php';
