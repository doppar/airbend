<?php

namespace Doppar\Airbend\Broadcasting;

use Phaseolies\Support\Facades\Log;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Doppar\Airbend\Configuration\ConfigurationManager;
use Doppar\Airbend\Monitoring\MetricsCollector;
use Doppar\Airbend\Exceptions\WebSocketException;

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
     * @param string|null $host
     * @param int|null $port
     * @param bool|null $ssl
     * @param WebSocketHandler|null $handler
     */
    public function __construct(?string $host = null, ?int $port = null, ?bool $ssl = null, ?WebSocketHandler $handler = null)
    {
        $this->host = $host ?? ConfigurationManager::get('websocket.host', '127.0.0.1');
        $this->port = $port ?? ConfigurationManager::get('websocket.port', 6001);
        $this->ssl = $ssl ?? ConfigurationManager::get('websocket.ssl', false);
        $this->handler = $handler ?? new WebSocketHandler();
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

        if ($this->ssl) {
            $worker->transport = 'ssl';
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

        $worker->onWorkerStart = function () use ($handler) {
            try {
                // Create and initialize Redis subscriber in worker context
                $this->redisSubscriber = new RedisSubscriber($handler);
                $this->redisSubscriber->initialize();
            } catch (\Exception $e) {
                Log::error('Failed to initialize Redis subscriber: ' . $e->getMessage());
                Log::error('Stack trace: ' . $e->getTraceAsString());
            }

            // Setup periodic tasks
            $this->setupPeriodicTasks($handler);
        };

        Worker::runAll();
    }

    /**
     * Setup periodic maintenance tasks
     *
     * @param WebSocketHandler $handler
     * @return void
     */
    protected function setupPeriodicTasks(WebSocketHandler $handler): void
    {
        // Heartbeat every 30 seconds
        $heartbeatInterval = ConfigurationManager::get('websocket.heartbeat_interval', 30);
        Timer::add($heartbeatInterval, function () use ($handler) {
            $handler->sendHeartbeat();
        });

        // Clean up stale connections every 60 seconds
        Timer::add(60, function () use ($handler) {
            $handler->cleanupStaleConnections();
        });

        // Log statistics every 5 minutes (300 seconds)
        // Timer::add(300, function () use ($handler) {
        //     $this->logStatistics($handler);
        // });

        // Memory usage monitoring every 60 seconds
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
     * Log server statistics
     *
     * @param WebSocketHandler $handler
     * @return void
     */
    protected function logStatistics(WebSocketHandler $handler): void
    {
        try {
            $stats = $handler->getChannelStats();
            $metrics = MetricsCollector::getMetrics();
            $performanceStats = MetricsCollector::getPerformanceStats();

            Log::info('WebSocket Server Statistics', [
                'uptime' => $this->getUptimeFormatted(),
                'connections' => $metrics['connections'],
                'messages' => $metrics['messages'],
                'channels' => $metrics['channels'],
                'errors' => $metrics['errors'],
                'performance' => $performanceStats,
                'memory_usage_mb' => $performanceStats['memory']['current_mb'],
                'handler_stats' => [
                    'total_connections' => $stats['total_connections'] ?? 0,
                    'total_channels' => $stats['total_channels'] ?? 0,
                    'channels' => array_map(function ($channel) {
                        return [
                            'type' => $channel['type'] ?? 'unknown',
                            'subscribers' => $channel['subscriber_count'] ?? 0,
                        ];
                    }, $stats['channels'] ?? [])
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error logging statistics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
}
