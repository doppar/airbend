<?php

namespace Doppar\Airbend\Monitoring;

/**
 * Metrics collector for monitoring Airbend performance
 */
class MetricsCollector
{
    /**
     * Metrics storage
     *
     * @var array<string, array>
     */
    protected static array $metrics = [
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
    protected static ?float $startTime = null;

    /**
     * Record a connection event
     *
     * @param string $type ('connect', 'disconnect', 'failed')
     * @return void
     */
    public static function recordConnection(string $type): void
    {
        match ($type) {
            'connect' => [
                static::$metrics['connections']['total']++,
                static::$metrics['connections']['active']++,
            ],
            'disconnect' => static::$metrics['connections']['active']--,
            'failed' => static::$metrics['connections']['failed']++,
            default => null,
        };

        // Ensure active connections don't go negative
        static::$metrics['connections']['active'] = max(0, static::$metrics['connections']['active']);
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
        if (isset(static::$metrics['messages'][$type])) {
            static::$metrics['messages'][$type] += $count;
        }
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
        $key = match ($type) {
            'subscription' => 'subscriptions',
            'unsubscription' => 'unsubscriptions',
            'broadcast' => 'broadcasts',
            default => null,
        };

        if ($key && isset(static::$metrics['channels'][$key])) {
            static::$metrics['channels'][$key] += $count;
        }
    }

    /**
     * Record an error event
     *
     * @param string $type ('connection', 'broadcast', 'authentication')
     * @return void
     */
    public static function recordError(string $type): void
    {
        $key = $type . '_errors';
        if (isset(static::$metrics['errors'][$key])) {
            static::$metrics['errors'][$key]++;
        }
    }

    /**
     * Start performance timing
     *
     * @return void
     */
    public static function startTiming(): void
    {
        static::$startTime = microtime(true);
    }

    /**
     * End performance timing and record
     *
     * @param string $operation
     * @return float The elapsed time
     */
    public static function endTiming(string $operation = 'default'): float
    {
        if (static::$startTime === null) {
            return 0.0;
        }

        $elapsed = microtime(true) - static::$startTime;
        static::$metrics['performance']['response_times'][] = [
            'operation' => $operation,
            'time' => $elapsed,
            'timestamp' => time(),
        ];

        static::$startTime = null;

        // Keep only the last 100 timing records
        if (count(static::$metrics['performance']['response_times']) > 100) {
            static::$metrics['performance']['response_times'] = array_slice(
                static::$metrics['performance']['response_times'], 
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
        static::$metrics['performance']['memory_usage'][] = [
            'usage' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'timestamp' => time(),
        ];

        // Keep only the last 100 memory records
        if (count(static::$metrics['performance']['memory_usage']) > 100) {
            static::$metrics['performance']['memory_usage'] = array_slice(
                static::$metrics['performance']['memory_usage'], 
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
        // Record current memory usage
        static::recordMemoryUsage();
        
        return static::$metrics;
    }

    /**
     * Get specific metric category
     *
     * @param string $category
     * @return array<string, mixed>
     */
    public static function getMetricCategory(string $category): array
    {
        return static::$metrics[$category] ?? [];
    }

    /**
     * Get performance statistics
     *
     * @return array<string, mixed>
     */
    public static function getPerformanceStats(): array
    {
        $responseTimes = array_column(static::$metrics['performance']['response_times'], 'time');
        $memoryUsage = static::$metrics['performance']['memory_usage'];

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
        static::$metrics = [
            'connections' => ['total' => 0, 'active' => 0, 'failed' => 0],
            'messages' => ['sent' => 0, 'received' => 0, 'failed' => 0],
            'channels' => ['subscriptions' => 0, 'unsubscriptions' => 0, 'broadcasts' => 0],
            'performance' => ['memory_usage' => [], 'response_times' => []],
            'errors' => ['connection_errors' => 0, 'broadcast_errors' => 0, 'authentication_errors' => 0]
        ];
        static::$startTime = null;
    }

    /**
     * Get metrics summary for logging
     *
     * @return array<string, mixed>
     */
    public static function getSummary(): array
    {
        $performanceStats = static::getPerformanceStats();
        
        return [
            'connections' => static::$metrics['connections']['active'],
            'total_messages' => static::$metrics['messages']['sent'] + static::$metrics['messages']['received'],
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
        $totalErrors = array_sum(static::$metrics['errors']);
        $totalOperations = static::$metrics['connections']['total'] + 
                         static::$metrics['messages']['sent'] + 
                         static::$metrics['channels']['broadcasts'];
        
        return $totalOperations > 0 ? round(($totalErrors / $totalOperations) * 100, 2) : 0.0;
    }
}