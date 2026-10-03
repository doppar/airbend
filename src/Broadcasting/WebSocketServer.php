<?php

namespace Doppar\Airbend\Broadcasting;

use Phaseolies\Support\Facades\Log;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Doppar\Airbend\Configuration\ConfigurationManager;
use Doppar\Airbend\Exceptions\WebSocketException;
use Channel\Server as ChannelServer;
use Channel\Client as ChannelClient;

class WebSocketServer
{
    /**
     * The host address to bind
     *
     * @var string
     */
    protected string $host;

    /**
     * The port to listen on
     *
     * @var int
     */
    protected int $port;

    /**
     * Internal broadcast port
     *
     * @var int
     */
    protected int $internalPort;

    /**
     * Interface the internal broadcast server binds to (loopback by default)
     *
     * @var string
     */
    protected string $internalHost;

    /**
     * Enable SSL/TLS
     *
     * @var bool
     */
    protected bool $ssl;

    /**
     * The WebSocket handler
     *
     * @var WebSocketHandler
     */
    protected WebSocketHandler $handler;

    /**
     * Internal TCP server for broadcasting
     *
     * @var Worker|null
     */
    protected ?Worker $internalServer = null;

    /**
     * WebSocket worker instance
     *
     * @var Worker|null
     */
    protected ?Worker $webSocketWorker = null;

    /**
     * Server start time
     *
     * @var int
     */
    protected int $startTime;

    /**
     * Connections from internal broadcast clients
     *
     * @var array
     */
    protected array $internalConnections = [];

    /**
     * Broadcast driver type
     *
     * @var string
     */
    protected string $broadcastDriver;

    /**
     * Create a new WebSocket server instance.
     *
     * @param string|null $host
     * @param int|null $port
     * @param bool|null $ssl
     * @param WebSocketHandler|null $handler
     */
    public function __construct(?string $host = null, ?int $port = null, ?bool $ssl = null, ?WebSocketHandler $handler = null)
    {
        $this->host = $host ?? ConfigurationManager::get('websocket.host', '127.0.0.1');
        $this->port = $port ?? ConfigurationManager::get('websocket.port', 6001);
        $this->internalPort = ConfigurationManager::get('websocket.internal_port', 6002);
        $this->internalHost = ConfigurationManager::get('websocket.internal_host', '127.0.0.1');
        $this->ssl = $ssl ?? ConfigurationManager::get('websocket.ssl', false);
        $this->handler = $handler ?? new WebSocketHandler();
        $this->broadcastDriver = ConfigurationManager::get('default');
        $this->startTime = time();
    }

    /**
     * Start the WebSocket server
     *
     * @return void
     * @throws WebSocketException
     */
    public function run(): void
    {
        // Fail fast: a server that cannot verify authorizations must not start
        ConfigurationManager::appKey();
        ConfigurationManager::appSecret();

        if ($this->broadcastDriver === 'workerman') {
            $channelHost = ConfigurationManager::get('websocket.channel_host', '127.0.0.1');
            $channelPort = ConfigurationManager::get('websocket.channel_port', 2206);
            new ChannelServer($channelHost, $channelPort);
        }

        $this->createWebSocketServer();

        if ($this->broadcastDriver === 'workerman') {
            $this->createInternalServer();
        }

        Worker::runAll();
    }

    /**
     * Create the WebSocket server for client connections
     *
     * @return void
     * @throws WebSocketException
     */
    protected function createWebSocketServer(): void
    {
        $host = $this->host;
        $port = $this->port;

        $context = [];
        if ($this->ssl) {
            $sslCert = ConfigurationManager::get('websocket.ssl_cert');
            $sslKey = ConfigurationManager::get('websocket.ssl_key');

            if (!$sslCert || !file_exists($sslCert)) {
                throw WebSocketException::connectionFailed('SSL certificate file not found or not configured');
            }

            if (!$sslKey || !file_exists($sslKey)) {
                throw WebSocketException::connectionFailed('SSL private key file not found or not configured');
            }

            $context = [
                'ssl' => [
                    'local_cert' => $sslCert,
                    'local_pk' => $sslKey,
                    'allow_self_signed' => ConfigurationManager::get('websocket.allow_self_signed', false),
                    'verify_peer' => false,
                ],
            ];
        }

        $worker = new Worker("websocket://{$host}:{$port}", $context);
        $worker->name = 'WebSocket Server';
        $worker->count = 1;
        $this->webSocketWorker = $worker;

        if ($this->ssl) {
            $worker->transport = 'ssl';
        }

        $server = $this;

        $worker->onConnect = function (TcpConnection $connection) use ($server) {
            // Never let an uncaught error in a callback stop the whole worker (and every client)
            $connection->errorHandler = function (\Throwable $e) {
                Log::error('Unhandled WebSocket connection error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            };
            $connection->maxPackageSize = WebSocketHandler::MAX_MESSAGE_BYTES;

            $server->handler->onOpen($connection);
        };

        $worker->onMessage = function (TcpConnection $connection, $data) use ($server) {
            $server->handler->onMessage($connection, (string) $data);
        };

        $worker->onClose = function (TcpConnection $connection) use ($server) {
            $server->handler->onClose($connection);
        };

        $worker->onError = function (TcpConnection $connection, $code, $msg) use ($server) {
            $server->handler->onError($connection, $code, $msg);
        };

        $worker->onWorkerStart = function () use ($server, $worker) {
            $worker->handler = $server->handler;

            if ($server->broadcastDriver === 'workerman') {
                $channelHost = ConfigurationManager::get('websocket.channel_host', '127.0.0.1');
                $channelPort = ConfigurationManager::get('websocket.channel_port', 2206);
                ChannelClient::connect($channelHost, $channelPort);

                ChannelClient::on('airbend.broadcast', function ($data) use ($server) {
                    $event = is_array($data) ? ($data['event'] ?? '') : '';
                    $channel = is_array($data) ? ($data['channel'] ?? '') : '';
                    $messageData = $data['data'] ?? [];
                    $exceptSocketId = $data['socket_id'] ?? null;

                    if (!is_string($event) || !is_string($channel) || $event === '' || $channel === '') {
                        Log::warning("Invalid broadcast message from Channel", $data);
                        return;
                    }

                    $broadcastMessage = [
                        'event' => $event,
                        'data' => $messageData,
                        'channel' => $channel,
                    ];

                    $exceptConnectionId = null;
                    if (is_string($exceptSocketId) && $exceptSocketId !== '') {
                        $exceptConnectionId = $server->findConnectionIdBySocketId($exceptSocketId);
                    }

                    $server->handler->broadcastToChannel($channel, $broadcastMessage, $exceptConnectionId);
                });
            }

            if ($server->broadcastDriver === 'redis') {
                $server->initializeRedisSubscriber($server->handler);
            }

            $server->setupPeriodicTasks($server->handler);
        };
    }

    /**
     * Initialize Redis subscriber for Redis driver
     *
     * @param WebSocketHandler $handler
     * @return void
     */
    protected function initializeRedisSubscriber(WebSocketHandler $handler): void
    {
        try {
            $redisSubscriber = new \Doppar\Airbend\Broadcasting\RedisSubscriber($handler);
            $redisSubscriber->initialize();
        } catch (\Exception $e) {
            Log::error('Failed to initialize Redis subscriber: ' . $e->getMessage());
        }
    }

    /**
     * Create the internal TCP server
     *
     * @return void
     */
    protected function createInternalServer(): void
    {
        $this->internalServer = new Worker("tcp://{$this->internalHost}:{$this->internalPort}");
        $this->internalServer->name = 'Internal Broadcast Server';
        $this->internalServer->count = 1;

        $server = $this;

        $this->internalServer->onConnect = function (TcpConnection $connection) use ($server) {
            $connection->maxPackageSize = 1024 * 1024;
            $connection->errorHandler = function (\Throwable $e) {
                Log::error('Unhandled internal server error', ['error' => $e->getMessage()]);
            };
            $server->onInternalConnect($connection);
        };

        $this->internalServer->onMessage = function (TcpConnection $connection, $data) use ($server) {
            $server->onInternalMessage($connection, $data);
        };

        $this->internalServer->onClose = function (TcpConnection $connection) use ($server) {
            $server->onInternalClose($connection);
        };

        $this->internalServer->onError = function (TcpConnection $connection, $code, $msg) use ($server) {
            Log::error("Internal server error: {$msg} (code: {$code})");
        };

        $this->internalServer->onWorkerStart = function () use ($server) {
            $channelHost = ConfigurationManager::get('websocket.channel_host', '127.0.0.1');
            $channelPort = ConfigurationManager::get('websocket.channel_port', 2206);
            ChannelClient::connect($channelHost, $channelPort);
        };
    }

    /**
     * Handle internal server connection (from WorkermanDriver)
     *
     * @param TcpConnection $connection
     * @return void
     */
    protected function onInternalConnect(TcpConnection $connection): void
    {
        $connectionId = $connection->id;
        $connection->lastActivity = time();

        $this->internalConnections[$connectionId] = $connection;
    }

    /**
     * Handle internal messages (broadcasts from WorkermanDriver)
     *
     * @param TcpConnection $connection
     * @param mixed $data
     * @return void
     */
    protected function onInternalMessage(TcpConnection $connection, $data): void
    {
        $connection->lastActivity = time();

        try {
            $message = InternalMessageSigner::verify(trim((string) $data));

            if ($message === null) {
                Log::warning('Rejected unsigned, forged or expired internal broadcast', [
                    'remote' => $connection->getRemoteAddress(),
                ]);
                $connection->send(json_encode([
                    'type' => 'error',
                    'message' => 'Invalid signature',
                ]) . "\n");

                return;
            }

            $this->handleInternalBroadcast($message);

            $connection->send(json_encode([
                'type' => 'ack',
                'message_id' => $message['message_id'] ?? null,
                'status' => 'processed',
            ]) . "\n");
        } catch (\Throwable $e) {
            Log::error('Error processing internal message', [
                'error' => $e->getMessage(),
            ]);

            $connection->send(json_encode([
                'type' => 'error',
                'message' => 'Internal error',
            ]) . "\n");
        }
    }

    /**
     * Handle internal disconnection
     *
     * @param TcpConnection $connection
     * @return void
     */
    protected function onInternalClose(TcpConnection $connection): void
    {
        if (isset($connection->id)) {
            $connectionId = $connection->id;
            unset($this->internalConnections[$connectionId]);
        }
    }

    /**
     * Handle broadcast messages from internal server
     *
     * @param array $message
     * @return void
     */
    protected function handleInternalBroadcast(array $message): void
    {
        if (!isset($message['type']) || $message['type'] !== 'broadcast') {
            Log::warning("Ignoring non-broadcast message from internal client", ['type' => $message['type'] ?? 'unknown']);
            return;
        }

        $event = $message['event'] ?? '';
        $channel = $message['channel'] ?? '';
        $data = $message['data'] ?? [];
        $exceptSocketId = $message['socket_id'] ?? null;

        if (!is_string($event) || !is_string($channel) || $event === '' || $channel === '') {
            Log::warning("Invalid broadcast message - missing event or channel", $message);
            return;
        }

        $channelMessage = [
            'event' => $event,
            'channel' => $channel,
            'data' => $data,
            'socket_id' => $exceptSocketId,
        ];

        ChannelClient::publish('airbend.broadcast', $channelMessage);
    }


    /**
     * Find connection ID by socket ID
     *
     * @param string $socketId
     * @return int|null
     */
    protected function findConnectionIdBySocketId(string $socketId): ?int
    {
        foreach ($this->handler->clientMetadata as $connectionId => $metadata) {
            if (($metadata['socket_id'] ?? null) === $socketId) {
                return $connectionId;
            }
        }
        return null;
    }

    /**
     * Setup periodic maintenance tasks
     *
     * @param WebSocketHandler $handler
     * @return void
     */
    protected function setupPeriodicTasks(WebSocketHandler $handler): void
    {
        $heartbeatInterval = ConfigurationManager::get('websocket.heartbeat_interval', 30);
        Timer::add($heartbeatInterval, function () use ($handler) {
            $handler->sendHeartbeat();
        });

        Timer::add(60, function () use ($handler) {
            $handler->cleanupStaleConnections();
        });

        if ($this->broadcastDriver === 'workerman') {
            Timer::add(60, function () {
                $this->cleanupStaleInternalConnections();
            });
        }

        Timer::add(60, function () {
            $memoryUsage = memory_get_usage(true) / 1024 / 1024;
            $memoryPeak = memory_get_peak_usage(true) / 1024 / 1024;

            if ($memoryUsage > 50) {
                Log::warning('High memory usage detected', [
                    'memory_usage_mb' => round($memoryUsage, 2),
                    'memory_peak_mb' => round($memoryPeak, 2),
                ]);
            }
        });
    }

    /**
     * Clean up stale internal connections
     *
     * @return void
     */
    protected function cleanupStaleInternalConnections(): void
    {
        $timeout = 300;
        $now = time();
        $cleaned = 0;

        foreach ($this->internalConnections as $connectionId => $connection) {
            if ($now - $connection->lastActivity > $timeout) {
                $connection->close();
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            Log::info("Cleaned up {$cleaned} stale internal connections");
        }
    }

    /**
     * Get the WebSocket handler
     *
     * @return WebSocketHandler
     */
    public function getHandler(): WebSocketHandler
    {
        return $this->handler;
    }

    /**
     * Get server statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        $stats = $this->handler->getChannelStats();
        $stats['uptime'] = $this->getUptimeFormatted();
        $stats['broadcast_driver'] = $this->broadcastDriver;

        if ($this->broadcastDriver === 'workerman') {
            $stats['internal_connections'] = count($this->internalConnections);
        }

        return $stats;
    }

    /**
     * Get server uptime in seconds
     *
     * @return int
     */
    public function getUptime(): int
    {
        return time() - $this->startTime;
    }

    /**
     * Get formatted uptime string
     *
     * @return string
     */
    public function getUptimeFormatted(): string
    {
        $uptime = $this->getUptime();
        $hours = floor($uptime / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        $seconds = $uptime % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }
}
