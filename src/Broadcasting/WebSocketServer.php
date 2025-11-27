<?php

namespace Doppar\Airbend\Broadcasting;

use Phaseolies\Support\Facades\Log;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Doppar\Airbend\Broadcasting\RedisSubscriber;
use Doppar\Airbend\Broadcasting\WebSocketHandler;

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
     * Redis subscriber instance
     *
     * @var RedisSubscriber|null
     */
    protected ?RedisSubscriber $redisSubscriber = null;

    /**
     * Server start time
     *
     * @var int
     */
    protected int $startTime;

    /**
     * Create a new WebSocket server instance.
     *
     * @param string $host
     * @param int $port
     * @param bool $ssl
     */
    public function __construct(string $host = '127.0.0.1', int $port = 6001, bool $ssl = false)
    {
        $this->host = $host;
        $this->port = $port;
        $this->ssl = $ssl;
        $this->handler = new WebSocketHandler();
        $this->startTime = time();
    }

    /**
     * Start the WebSocket server
     *
     * @return void
     */
    public function run(): void
    {
        // Workerman-based WebSocket server
        $host = $this->host;
        $port = $this->port;

        $context = [];
        if ($this->ssl) {
            $context = [
                'ssl' => [
                    'local_cert' => config('airbend.websocket.ssl_cert'),
                    'local_pk' => config('airbend.websocket.ssl_key'),
                    'allow_self_signed' => config('airbend.websocket.allow_self_signed', false),
                    'verify_peer' => false,
                ],
            ];
        }

        $worker = new Worker("websocket://{$host}:{$port}", $context);
        $worker->name = 'WebSocket Server';
        $worker->count = 1;

        if ($this->ssl) {
            $worker->transport = 'ssl';
            Log::info('SSL/TLS enabled for WebSocket server');
        }

        $handler = $this->handler;

        $worker->onConnect = function (TcpConnection $connection) use ($handler) {
            $handler->onOpen($connection);
        };

        $worker->onMessage = function (TcpConnection $connection, $data) use ($handler) {
            $handler->onMessage($connection, $data);
        };

        $worker->onClose = function (TcpConnection $connection) use ($handler) {
            $handler->onClose($connection);
        };

        $worker->onError = function (TcpConnection $connection, $code, $msg) use ($handler) {
            $handler->onError($connection, $code, $msg);
        };

        $self = $this;
        $worker->onWorkerStart = function () use ($self) {
            // Initialize Redis subscriber within this worker
            if ($self->redisSubscriber) {
                try {
                    $self->redisSubscriber->initialize();
                } catch (\Exception $e) {
                    Log::error('Failed to initialize Redis in worker: ' . $e->getMessage());
                }
            }
            
            // Set up periodic tasks (heartbeat, cleanup, stats)
            $self->setupPeriodicTasks();
        };

        // Create Redis subscriber instance BEFORE running workers
        $this->initializeRedisSubscriber();

        Log::info("WebSocket server starting on ws://{$this->host}:{$this->port}");
        Log::info('Waiting for connections...');

        // This blocks and runs all workers (WebSocket + Redis subscriber)
        Worker::runAll();
    }

    /**
     * Initialize Redis subscriber
     *
     * @return void
     */
    protected function initializeRedisSubscriber(): void
    {
        try {
            $this->redisSubscriber = new RedisSubscriber($this->handler);
            Log::info('Redis subscriber instance created');
        } catch (\Exception $e) {
            Log::error('Failed to create Redis subscriber: ' . $e->getMessage());
            Log::warning('Server will continue without Redis broadcasting');
        }
    }

    /**
     * Setup periodic maintenance tasks
     *
     * @return void
     */
    protected function setupPeriodicTasks(): void
    {
        // Heartbeat every 30 seconds
        $heartbeatInterval = config('airbend.websocket.heartbeat_interval', 30);
        Timer::add($heartbeatInterval, function () {
            $this->handler->sendHeartbeat();
            Log::debug('Heartbeat sent to all connected clients');
        });

        // Clean up stale connections every 60 seconds
        Timer::add(60, function () {
            $this->handler->cleanupStaleConnections();
        });

        // Log statistics every 5 minutes (300 seconds)
        Timer::add(300, function () {
            $this->logStatistics();
        });

        // Memory usage monitoring every 60 seconds
        Timer::add(60, function () {
            $memoryUsage = memory_get_usage(true) / 1024 / 1024;
            $memoryPeak = memory_get_peak_usage(true) / 1024 / 1024;

            if ($memoryUsage > 50) { // Log if using more than 50MB
                Log::warning('High memory usage detected', [
                    'memory_usage_mb' => round($memoryUsage, 2),
                    'memory_peak_mb' => round($memoryPeak, 2),
                ]);
            }
        });
    }

    /**
     * Log server statistics
     *
     * @return void
     */
    protected function logStatistics(): void
    {
        try {
            $stats = $this->handler->getChannelStats();
            Log::info('WebSocket Server Statistics', [
                'uptime' => $this->getUptimeFormatted(),
                'total_connections' => $stats['total_connections'] ?? 0,
                'total_channels' => $stats['total_channels'] ?? 0,
                'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'channels' => array_map(function ($channel) {
                    return [
                        'type' => $channel['type'] ?? 'unknown',
                        'subscribers' => $channel['subscriber_count'] ?? 0,
                    ];
                }, $stats['channels'] ?? [])
            ]);
        } catch (\Exception $e) {
            Log::error('Error logging statistics: ' . $e->getMessage());
        }
    }

    /**
     * Setup graceful shutdown handler
     *
     * @return void
     */
    protected function setupShutdownHandler(): void
    {
        // Handle SIGINT (Ctrl+C) and SIGTERM
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                $this->gracefulShutdown('SIGINT');
            });

            pcntl_signal(SIGTERM, function () {
                $this->gracefulShutdown('SIGTERM');
            });

            // Enable signal dispatching
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
            }

            Log::debug('Signal handlers registered (SIGINT, SIGTERM)');
        } else {
            Log::warning('pcntl extension not available, signal handling disabled');
        }

        // Register shutdown function for fatal errors
        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
                Log::emergency('Fatal error occurred - Server shutting down', [
                    'message' => $error['message'],
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'type' => $error['type']
                ]);
            }
        });
    }

    /**
     * Gracefully shutdown the server
     *
     * @param string $signal
     * @return void
     */
    protected function gracefulShutdown(string $signal): void
    {
        Log::info("Received {$signal}, shutting down gracefully...");

        // Disconnect Redis subscriber
        if ($this->redisSubscriber) {
            try {
                $this->redisSubscriber->disconnect();
                Log::info('Redis subscriber disconnected');
            } catch (\Exception $e) {
                Log::error('Error disconnecting Redis subscriber: ' . $e->getMessage());
            }
        }

        // Notify and close all client connections
        try {
            $clientCount = $this->handler->clients->count();
            Log::info("Closing {$clientCount} client connections...");

            $closedCount = 0;
            foreach ($this->handler->clients as $client) {
                try {
                    // Send shutdown notification
                    $this->handler->sendToClient($client, [
                        'event' => 'doppar:server_shutdown',
                        'data' => json_encode([
                            'message' => 'Server is shutting down for maintenance',
                            'code' => 'SERVER_SHUTDOWN',
                            'timestamp' => time()
                        ]),
                    ]);

                    // Close the connection
                    $client->close();
                    $closedCount++;
                } catch (\Exception $e) {
                    Log::error("Error closing connection {$client->resourceId}: {$e->getMessage()}");
                }
            }

            Log::info("Successfully closed {$closedCount} connections");
        } catch (\Exception $e) {
            Log::error('Error during connection cleanup: ' . $e->getMessage());
        }

        // Stop the event loop
        try {
            $this->loop->stop();
            Log::info('Event loop stopped');
        } catch (\Exception $e) {
            Log::error('Error stopping event loop: ' . $e->getMessage());
        }

        Log::info('WebSocket server shutdown complete');
    }

    /**
     * Get the event loop instance
     *
     * @return \React\EventLoop\LoopInterface
     */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
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
        return $this->handler->getChannelStats();
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

    /**
     * Check if server is running
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->server !== null;
    }
}
