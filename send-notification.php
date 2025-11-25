<?php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;

$text = isset($_GET['msg']) ? $_GET['msg'] : 'Hello from PHP!';

// Ikokonek tayo sa WS server bilang "user" na sender, tapos send msg
$context = stream_context_create();
$fp = stream_socket_client("tcp://127.0.0.1:2346", $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $context);
if ($fp) {
    fwrite($fp, $text);
    fclose($fp);
}
?>