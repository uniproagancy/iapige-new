<?php

declare(strict_types=1);

use App\Services\Social\PostArtwork;

require __DIR__.'/bootstrap-artwork.php';

$options = getopt('', ['snapshot:', 'output:']);
$snapshotPath = $options['snapshot'] ?? '';
$output = $options['output'] ?? '';
if (! is_file($snapshotPath) || ! is_dir($output)) {
    throw new InvalidArgumentException('Pass --snapshot=<catalog-snapshot.json> and --output=<existing-preview-directory>.');
}
$snapshot = json_decode(file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
$renderer = new PostArtwork;
foreach (['black_friday', 'new_year', 'evergreen'] as $theme) {
    $renderer->render($snapshot, $theme, $output.'/'.$theme.'.png');
    echo $theme.".png\n";
}
