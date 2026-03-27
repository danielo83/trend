<?php
$config = require __DIR__ . '/config.php';

$feedPath = ($config['base_dir'] ?? __DIR__) . '/data/feed-twitter.xml';

if (!file_exists($feedPath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Feed X/Twitter non ancora disponibile.';
    exit;
}

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
readfile($feedPath);
