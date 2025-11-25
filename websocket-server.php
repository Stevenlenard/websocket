<?php
// websocket-server.php
// Requires composer packages: cboden/ratchet (run: composer require cboden/ratchet)
// Listens on ws://0.0.0.0:8080 for websocket clients and tcp://127.0.0.1:2346 for local broadcasts.

require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory as LoopFactory;
use React\Socket\Server as ReactSocketServer;
use React\Socket\ConnectionInterface as ReactConnection;

class Broadcaster implements MessageComponentInterface {
    /** @var \SplObjectStorage */
    protected $clients;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
        echo "[broadcaster] initialized\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "[broadcaster] client connected ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        // We don't expect normal websocket clients to send messages;
        // but if they do, broadcast to everyone.
        $this->broadcast($msg);
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "[broadcaster] client disconnected ({$conn->resourceId})\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "[broadcaster] error: " . $e->getMessage() . "\n";
        $conn->close();
    }

    public function broadcast($message) {
        // ensure message is a string
        $payload = is_string($message) ? $message : json_encode($message);
        foreach ($this->clients as $client) {
            try {
                $client->send($payload);
            } catch (\Exception $e) {
                // ignore send errors
            }
        }
        echo "[broadcaster] broadcasted to " . count($this->clients) . " clients: " . (is_string($message) ? $message : substr(json_encode($message),0,120)) . "\n";
    }
}

$loop = LoopFactory::create();
$broadcaster = new Broadcaster();

// Start websocket server (ws://0.0.0.0:8080)
$wsHost = '0.0.0.0:8080';
$socketServer = new ReactSocketServer($wsHost, $loop);
$wsServer = new IoServer(
    new HttpServer(
        new WsServer($broadcaster)
    ),
    $socketServer,
    $loop
);
echo "[server] WebSocket listening on ws://{$wsHost}\n";

// Also start a tiny TCP server for local PHP scripts to send JSON via stream_socket_client to 127.0.0.1:2346
$tcpHost = '127.0.0.1:2346';
$tcpServer = new ReactSocketServer($tcpHost, $loop);
$tcpServer->on('connection', function (ReactConnection $conn) use ($broadcaster) {
    $remote = $conn->getRemoteAddress();
    echo "[tcp] connection from {$remote}\n";

    $buffer = '';
    $conn->on('data', function ($data) use (&$buffer, $conn, $broadcaster) {
        $buffer .= $data;
        // If client sends newline-terminated JSON (or immediate), attempt to parse and broadcast.
        // We'll accept whatever arrives as a single JSON payload.
        $trim = trim($buffer);
        if ($trim === '') return;
        // Try decode; if not valid JSON, just broadcast the raw string.
        $payload = $trim;
        $broadcast = $payload;
        // If multiple messages could arrive, this simple server treats the whole buffer as one message.
        $broadcaster->broadcast($broadcast);
        $conn->end(); // close after one message (PHP scripts use one-shot)
    });

    $conn->on('close', function () use ($remote) {
        echo "[tcp] closed {$remote}\n";
    });

    $conn->on('error', function ($e) use ($remote) {
        echo "[tcp] error {$remote}: " . $e->getMessage() . "\n";
    });
});
echo "[server] TCP listener on tcp://{$tcpHost} (local broadcast fallback)\n";

$loop->run();