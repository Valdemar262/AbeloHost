<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = is_string($path) ? realpath(__DIR__ . rawurldecode($path)) : false;
$assets = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;
$extensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'webp', 'svg', 'ico', 'woff2'];

if (
    $file !== false
    && str_starts_with($file, $assets)
    && is_file($file)
    && in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions, true)
) {
    return false;
}

require __DIR__ . '/index.php';
