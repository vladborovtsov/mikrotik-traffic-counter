<?php

declare(strict_types=1);

try {
    /** @var array{config: \App\Config\Configuration, database: \App\Database\Database, pdo: \PDO} $runtime */
    $runtime = require __DIR__ . '/../src/init.php';

    $config = $runtime['config'];
    $database = $runtime['database'];
    $db = $runtime['pdo'];

    $request = App\Http\Request::fromGlobals();
    $controller = new App\Controllers\ApiController(
        $request,
        $config,
        new App\Services\DeviceService($db),
        new App\Services\InterfaceService($db),
        new App\Services\TrafficService($db, $database),
        new App\Services\RequestGuardService($config)
    );

    $controller->handle()->send();
} catch (App\Controllers\InvalidRequestException $exception) {
    App\Http\Response::json(['error' => $exception->getMessage()], 400)->send();
} catch (Throwable $exception) {
    $message = $exception->getMessage();

    if (($_GET['_format'] ?? '') === 'plain') {
        App\Http\Response::text($message, 500)->send();
    }

    App\Http\Response::json(['error' => $message], 500)->send();
}
