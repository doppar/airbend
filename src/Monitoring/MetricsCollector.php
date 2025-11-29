<?php

namespace Doppar\Airbend\Monitoring;

use Doppar\Airbend\Configuration\ConfigurationManager;
use Exception;
use Predis\Client;

/**
 * Metrics collector for monitoring Airbend performance
 */
class MetricsCollector
{
    /**
     * Singleton instance
     *
     * @var MetricsCollector|null
     */
    protected static ?MetricsCollector $instance = null;

    /**
     * Redis connection for shared metrics
     *
     * @var Client|null
     */
    protected ?Client $redis = null;

    /**
     * Redis key prefix for metrics
     *
     * @var string
     */
    protected string $redisPrefix = 'airbend:metrics:';

    /**
     * Metrics storage
     *
     * @var array<string, array>
     */
    protected array $metrics = [
        'connections' => [
            'total' => 0,
            'active' => 0,
            'failed' => 0,
        ],
        'messages' => [
            'sent' => 0,
            'received' => 0,
            'failed' => 0,
        ],
        'channels' => [
            'subscriptions' => 0,
            'unsubscriptions' => 0,
            'broadcasts' => 0,
        ],
        'performance' => [
            'memory_usage' => [],
            'response_times' => [],
        ],
        'errors' => [
            'connection_errors' => 0,
            'broadcast_errors' => 0,
            'authentication_errors' => 0,
        ]
    ];

    /**
     * Start time for performance measurement
     *
     * @var float|null
     */
    protected ?float $startTime = null;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
        try {
            $config = ConfigurationManager::getRedisConfig();
            $this->redis = new Client($config['connection'], $config['options'] ?? []);

            $this->loadMetricsFromRedis();
        } catch (Exception $e) {
            $this->redis = null;
        }
    }

    /**
     * Get singleton instance
     *
     * @return MetricsCollector
     */
    public static function getInstance(): MetricsCollector
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Record a connection event
     *
     * @param string $type ('connect', 'disconnect', 'failed')
     * @return void
     */
    public static function recordConnection(string $type): void
    {
        $instance = static::getInstance();

        match ($type) {
            'connect' => [
                $instance->metrics['connections']['total']++,
                $instance->metrics['connections']['active']++,
                $instance->incrementRedisCounter('connections:total'),
                $instance->incrementRedisCounter('connections:active'),
            ],
            'disconnect' => [
                $instance->metrics['connections']['active']--,
                $instance->incrementRedisCounter('connections:active', -1),
            ],
            'failed' => [
                $instance->metrics['connections']['failed']++,
                $instance->incrementRedisCounter('connections:failed'),
            ],
            default => null,
        };

        // Ensure active connections don't go negative
        $instance->metrics['connections']['active'] = max(0, $instance->metrics['connections']['active']);

        // Save to Redis
        $instance->saveMetricsToRedis();
    }

    /**
     * Record a message event
     *
     * @param string $type ('sent', 'received', 'failed')
     * @param int $count
     * @return void
     */
    public static function recordMessage(string $type, int $count = 1): void
    {
        $instance = static::getInstance();

        if (isset($instance->metrics['messages'][$type])) {
            $instance->metrics['messages'][$type] += $count;
            $instance->incrementRedisCounter("messages:{$type}", $count);
        }

        $instance->saveMetricsToRedis();
    }

    /**
     * Record a channel event
     *
     * @param string $type ('subscription', 'unsubscription', 'broadcast')
     * @param int $count
     * @return void
     */
    public static function recordChannel(string $type, int $count = 1): void
    {
        $instance = static::getInstance();

        $key = match ($type) {
            'subscription' => 'subscriptions',
            'unsubscription' => 'unsubscriptions',
            'broadcast' => 'broadcasts',
            default => null,
        };

        if ($key && isset($instance->metrics['channels'][$key])) {
            $instance->metrics['channels'][$key] += $count;
            $instance->incrementRedisCounter("channels:{$key}", $count);
        }

        $instance->saveMetricsToRedis();
    }

    /**
     * Record an error event
     *
     * @param string $type ('connection', 'broadcast', 'authentication')
     * @return void
     */
    public static function recordError(string $type): void
    {
        $instance = static::getInstance();

        $key = $type . '_errors';
        if (isset($instance->metrics['errors'][$key])) {
            $instance->metrics['errors'][$key]++;
        }
    }

    /**
     * Start performance timing
     *
     * @return void
     */
    public static function startTiming(): void
    {
        $instance = static::getInstance();
        $instance->startTime = microtime(true);
    }

    /**
     * End performance timing and record
     *
     * @param string $operation
     * @return float The elapsed time
     */
    public static function endTiming(string $operation = 'default'): float
    {
        $instance = static::getInstance();

        if ($instance->startTime === null) {
            return 0.0;
        }

        $elapsed = microtime(true) - $instance->startTime;
        $instance->metrics['performance']['response_times'][] = [
            'operation' => $operation,
            'time' => $elapsed,
            'timestamp' => time(),
        ];

        $instance->startTime = null;

        // Keep only the last 100 timing records
        if (count($instance->metrics['performance']['response_times']) > 100) {
            $instance->metrics['performance']['response_times'] = array_slice(
                $instance->metrics['performance']['response_times'],
                -100
            );
        }

        return $elapsed;
    }

    /**
     * Record memory usage
     *
     * @return void
     */
    public static function recordMemoryUsage(): void
    {
        $instance = static::getInstance();

        $instance->metrics['performance']['memory_usage'][] = [
            'usage' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'timestamp' => time(),
        ];

        // Keep only the last 100 memory records
        if (count($instance->metrics['performance']['memory_usage']) > 100) {
            $instance->metrics['performance']['memory_usage'] = array_slice(
                $instance->metrics['performance']['memory_usage'],
                -100
            );
        }
    }

    /**
     * Get all metrics
     *
     * @return array<string, mixed>
     */
    public static function getMetrics(): array
    {
        $instance = static::getInstance();

        // Record current memory usage
        static::recordMemoryUsage();

        // Load fresh metrics from Redis if available
        if ($instance->redis) {
            $redisMetrics = [
                'connections' => [
                    'total' => $instance->getRedisCounter('connections:total'),
                    'active' => $instance->getRedisCounter('connections:active'),
                    'failed' => $instance->getRedisCounter('connections:failed'),
                ],
                'messages' => [
                    'sent' => $instance->getRedisCounter('messages:sent'),
                    'received' => $instance->getRedisCounter('messages:received'),
                    'failed' => $instance->getRedisCounter('messages:failed'),
                ],
                'channels' => [
                    'subscriptions' => $instance->getRedisCounter('channels:subscriptions'),
                    'unsubscriptions' => $instance->getRedisCounter('channels:unsubscriptions'),
                    'broadcasts' => $instance->getRedisCounter('channels:broadcasts'),
                ],
            ];

            // Merge Redis metrics with local metrics
            $instance->metrics = array_merge($instance->metrics, $redisMetrics);
        }

        return $instance->metrics;
    }

    /**
     * Get specific metric category
     *
     * @param string $category
     * @return array<string, mixed>
     */
    public static function getMetricCategory(string $category): array
    {
        $instance = static::getInstance();
        return $instance->metrics[$category] ?? [];
    }

    /**
     * Get performance statistics
     *
     * @return array<string, mixed>
     */
    public static function getPerformanceStats(): array
    {
        $instance = static::getInstance();

        $responseTimes = array_column($instance->metrics['performance']['response_times'], 'time');
        $memoryUsage = $instance->metrics['performance']['memory_usage'];

        $stats = [
            'response_times' => [
                'count' => count($responseTimes),
                'avg' => !empty($responseTimes) ? array_sum($responseTimes) / count($responseTimes) : 0,
                'min' => !empty($responseTimes) ? min($responseTimes) : 0,
                'max' => !empty($responseTimes) ? max($responseTimes) : 0,
            ],
            'memory' => [
                'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ]
        ];

        if (!empty($memoryUsage)) {
            $recentMemory = end($memoryUsage);
            $stats['memory']['recent_mb'] = round($recentMemory['usage'] / 1024 / 1024, 2);
        }

        return $stats;
    }

    /**
     * Reset all metrics
     *
     * @return void
     */
    public static function reset(): void
    {
        $instance = static::getInstance();

        $instance->metrics = [
            'connections' => ['total' => 0, 'active' => 0, 'failed' => 0],
            'messages' => ['sent' => 0, 'received' => 0, 'failed' => 0],
            'channels' => ['subscriptions' => 0, 'unsubscriptions' => 0, 'broadcasts' => 0],
            'performance' => ['memory_usage' => [], 'response_times' => []],
            'errors' => ['connection_errors' => 0, 'broadcast_errors' => 0, 'authentication_errors' => 0]
        ];
        $instance->startTime = null;

        $instance->resetRedisCounters();
    }

    /**
     * Get metrics summary for logging
     *
     * @return array<string, mixed>
     */
    public static function getSummary(): array
    {
        $instance = static::getInstance();
        $performanceStats = static::getPerformanceStats();

        return [
            'connections' => $instance->metrics['connections']['active'],
            'total_messages' => $instance->metrics['messages']['sent'] + $instance->metrics['messages']['received'],
            'error_rate' => static::calculateErrorRate(),
            'avg_response_time_ms' => round(($performanceStats['response_times']['avg'] ?? 0) * 1000, 2),
            'memory_usage_mb' => $performanceStats['memory']['current_mb'],
        ];
    }

    /**
     * Calculate error rate percentage
     *
     * @return float
     */
    protected static function calculateErrorRate(): float
    {
        $instance = static::getInstance();

        $totalErrors = array_sum($instance->metrics['errors']);
        $totalOperations = $instance->metrics['connections']['total'] +
            $instance->metrics['messages']['sent'] +
            $instance->metrics['channels']['broadcasts'];

        return $totalOperations > 0 ? round(($totalErrors / $totalOperations) * 100, 2) : 0.0;
    }

    /**
     * Load metrics from Redis
     *
     * @return void
     */
    protected function loadMetricsFromRedis(): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $storedMetrics = $this->redis->get($this->redisPrefix . 'data');
            if ($storedMetrics) {
                $decoded = json_decode($storedMetrics, true);
                if (is_array($decoded)) {
                    $this->metrics = array_merge($this->metrics, $decoded);
                }
            }
        } catch (Exception $e) {
            // Ignore Redis errors and use default metrics
        }
    }

    /**
     * Save metrics to Redis
     *
     * @return void
     */
    protected function saveMetricsToRedis(): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $this->redis->setex(
                $this->redisPrefix . 'data',
                3600, // 1 hour TTL
                json_encode($this->metrics)
            );
        } catch (Exception $e) {
            // Ignore Redis errors
        }
    }

    /**
     * Increment a counter in Redis atomically
     *
     * @param string $key
     * @param int $amount
     * @return void
     */
    protected function incrementRedisCounter(string $key, int $amount = 1): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $this->redis->incrby($this->redisPrefix . $key, $amount);
            $this->redis->expire($this->redisPrefix . $key, 3600); // 1 hour TTL
        } catch (Exception $e) {
            // Ignore Redis errors
        }
    }

    /**
     * Get counter value from Redis
     *
     * @param string $key
     * @return int
     */
    protected function getRedisCounter(string $key): int
    {
        if (!$this->redis) {
            return 0;
        }

        try {
            return (int) $this->redis->get($this->redisPrefix . $key);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Reset all Redis counters
     *
     * @return void
     */
    protected function resetRedisCounters(): void
    {
        if (!$this->redis) {
            return;
        }

        try {
            $keys = $this->redis->keys($this->redisPrefix . '*');
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
        } catch (Exception $e) {
            // Ignore Redis errors
        }
    }
}
