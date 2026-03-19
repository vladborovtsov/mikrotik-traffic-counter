<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Support/EnvFileLoader.php';
require_once __DIR__ . '/src/Config/Configuration.php';
require_once __DIR__ . '/src/Config/ConfigurationFactory.php';

static $configuration;

if ($configuration instanceof App\Config\Configuration) {
    return $configuration;
}

$configuration = App\Config\ConfigurationFactory::create(
    rootPath: __DIR__,
    runtimeEnvironment: array_merge($_SERVER, $_ENV)
);

return $configuration;
