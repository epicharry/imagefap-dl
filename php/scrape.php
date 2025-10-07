<?php

require_once 'ImageFapScraper.php';

header('Content-Type: application/json');

try {
    $url = $_GET['url'] ?? null;

    if (!$url) {
        throw new Exception('URL parameter is required');
    }

    $options = [
        'minTimePage' => 3000
    ];

    if (isset($_GET['minTimePage'])) {
        $options['minTimePage'] = intval($_GET['minTimePage']);
    }

    if (isset($_GET['maxRetries'])) {
        $options['maxRetries'] = intval($_GET['maxRetries']);
    }

    $scraper = new ImageFapScraper($options);

    $result = $scraper->scrapeTarget($url);

    echo json_encode([
        'success' => true,
        'url' => $url,
        'data' => $result
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
