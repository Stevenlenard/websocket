<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Worker;

$ws_worker = new Worker("websocket://0.0.0.0:2346");

$ws_worker->count = 1;

// Store all connections so you can broadcast
$ws_worker->connections = [];

$ws_worker->onConnect = function($connection) use ($ws_worker) {
    $ws_worker->connections[$connection->id] = $connection;
};

$ws_worker->onClose = function($connection) use ($ws_worker) {
    unset($ws_worker->connections[$connection->id]);
};

$ws_worker->onMessage = function($connection, $data) {
    // You can handle incoming messages if you want
};

// Custom broadcast for notification (listen on TCP input from PHP)
$tcp_worker = new Worker("tcp://127.0.0.1:2346");
$tcp_worker->onMessage = function($connection, $data) use ($ws_worker) {
    // Broadcast to all websocket clients
    foreach ($ws_worker->connections as $ws_connection) {
        $ws_connection->send($data);
    }
    $connection->close();
};

Worker::runAll();