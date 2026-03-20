<?php

declare(strict_types=1);

$autoloader = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoloader)) {
    require_once $autoloader;
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'App\\' => __DIR__ . '/../src/',
        'App\\Tests\\' => __DIR__ . '/',
    ];

    foreach ($prefixes as $prefix => $basePath) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relativeClass = substr($class, strlen($prefix));
        $path = $basePath . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    }
});
