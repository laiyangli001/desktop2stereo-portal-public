<?php
declare(strict_types=1);

// Local development only: never dispatch paths outside the public directory.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($path) && str_starts_with($path, '/assets/')) {
    $file = realpath(__DIR__ . rawurldecode($path));
    $base = realpath(__DIR__ . '/assets');
    if ($file !== false && $base !== false && str_starts_with($file, $base . DIRECTORY_SEPARATOR) &&
        is_file($file) && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['css', 'js', 'png', 'jpg', 'jpeg', 'svg', 'ico', 'woff2'], true)) {
        return false;
    }
}
require __DIR__ . '/index.php';
