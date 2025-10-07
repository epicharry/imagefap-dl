<?php

require_once 'ImageFapScraper.php';

$scraper = new ImageFapScraper([
    'minTimePage' => 2000,
    'maxRetries' => 3
]);

$url = $argv[1] ?? null;

if (!$url) {
    echo "Usage: php example.php <URL>\n";
    echo "\nSupported URL formats:\n";
    echo "  - https://www.imagefap.com/profile/<username>/galleries\n";
    echo "  - https://www.imagefap.com/profile/<username>/galleries?folderid=<folder-id>\n";
    echo "  - https://www.imagefap.com/gallery/<gallery-id>\n";
    exit(1);
}

try {
    echo "Scraping: $url\n\n";

    $result = $scraper->scrapeTarget($url);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
