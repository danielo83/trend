<?php
$config = require __DIR__ . '/config.php';

$feedPath = $config['feed_path'] ?? __DIR__ . '/data/feed.xml';

if (!file_exists($feedPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Feed non ancora disponibile.';
    exit;
}

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
readfile($feedPath);
