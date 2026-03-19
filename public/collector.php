<?php

declare(strict_types=1);

/*
Usage: /collector.php?sn=<SERIAL NUMBER>&interface=<INTERFACE NAME>&tx=<INTERFACE TX BYTES>&rx=<INTERFACE RX BYTES>&delta=<any value if needed>
*/

$_GET['action'] = 'collect';
$_GET['_format'] = 'plain';

require __DIR__ . '/api.php';
