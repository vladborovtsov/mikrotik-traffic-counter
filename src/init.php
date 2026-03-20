<?php
declare(strict_types=1);

/** @var array{config: \App\Config\Configuration, database: \App\Database\Database, pdo: \PDO} $runtime */
$runtime = require __DIR__ . '/bootstrap.php';

$config = $runtime['config'];
$database = $runtime['database'];
$db = $runtime['pdo'];

$database->initializeSchema();

return $runtime;
