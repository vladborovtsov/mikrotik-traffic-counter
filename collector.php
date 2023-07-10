<?php
/*
Usage: /collector.php?sn=<SERIAL NUMBER>&tx=<INTERFACE TX BYTES>&rx=<INTERFACE TX BYTES>&Delata=<any value if needed>
*/

require("init.php");

// Check input data
if (isset($_GET['sn'])
	and isset($_GET['tx']) and is_numeric($_GET['tx'])
	and isset($_GET['rx']) and is_numeric($_GET['rx'])) {
	$device_serial = substr($_GET['sn'], 0, 12);
} else {
	echo 'fail';
	exit;
}

//Update traffic data
$tx = $_GET['tx'];
$rx = $_GET['rx'];

// Check if device exists
$getDevice = $db->prepare('SELECT id, sn, comment, last_check, last_tx, last_rx FROM devices WHERE sn="'.$device_serial.'"');
$result = $getDevice->execute();
$device = $result->fetchArray(SQLITE3_ASSOC);
if (empty($device)) {
	//Add new device
	$addDevice = $db->prepare('INSERT INTO devices (sn, last_check, last_tx, last_rx)
	VALUES (:serial, :time, :tx, :rx)');
	$addDevice->bindValue(':serial', $device_serial);
	$addDevice->bindValue(':time', date('Y-m-d H:i:s'));
	$addDevice->bindValue(':tx', $tx);
	$addDevice->bindValue(':rx', $rx);
	$addDevice->execute();
	$device['id'] = $db->lastInsertRowid();
}
else {
	//Update last received data
	$updateData = $db->prepare('UPDATE devices SET last_check=:time, last_tx=:tx, last_rx=:rx WHERE id=:id');
	$updateData->bindValue(':id', $device['id']);
	$updateData->bindValue(':time', date('Y-m-d H:i:s'));
	$updateData->bindValue(':tx', $tx);
	$updateData->bindValue(':rx', $rx);
	$updateData->execute();
}

if (isset($_GET['delta']) && !empty($device) && isset($device['last_rx']) && isset($device['last_tx'])) {
	$last_rx = $device['last_rx'];
	$last_tx = $device['last_tx'];

	if ($tx >= $last_tx) $tx -= $last_tx;
	if ($rx >= $last_rx) $rx -= $last_rx;
}

$updateTraffic = $db->prepare('INSERT INTO traffic (device_id, timestamp, tx, rx)
	VALUES (:id, :time, :tx, :rx)');
$updateTraffic->bindValue(':id', $device['id']);
$updateTraffic->bindValue(':time', date('Y-m-d H:i:s'));
$updateTraffic->bindValue(':tx', $tx);
$updateTraffic->bindValue(':rx', $rx);
$updateTraffic->execute();



echo 'traffic data updated';
