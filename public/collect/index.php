<?php

declare(strict_types=1);

$_GET['action'] = 'collect';
$_GET['_format'] = $_GET['_format'] ?? 'plain';

require __DIR__ . '/../api.php';
