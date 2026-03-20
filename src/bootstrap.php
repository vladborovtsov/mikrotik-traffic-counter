<?php

declare(strict_types=1);

$autoloader = __DIR__ . '/../vendor/autoload.php';

if (is_file($autoloader)) {
    require_once $autoloader;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $path = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    });
}

/** @var \App\Config\Configuration $config */
$config = require __DIR__ . '/../configuration.php';
$database = App\Database\DatabaseFactory::create($config);

date_default_timezone_set($config->requireString('APP_TIMEZONE'));

return [
    'config' => $config,
    'database' => $database,
    'pdo' => $database->pdo(),
];
