<?php

namespace Doppar\Airbend\Broadcasting\Concerns;

use Predis\Client;
use Phaseolies\Support\Facades\Log;

trait HandleRedisConnection
{
    /**
     * Connect to Redis
     *
     * @return void
     */
    protected function handleRedisConnection(): void
    {
        try {
            $config = $this->getConnectionConfig();
            $options = $this->getConnectionOptions();

            $this->redis = new Client($config, $options);

            $this->redis->ping();

            Log::info("Redis subscriber connected successfully");
        } catch (\Exception $e) {
            Log::error("Failed to connect to Redis: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get connection config
     *
     * @return array|string
     */
    protected function getConnectionConfig()
    {
        $redisConfig = config('airbend.connections.redis');
        $connection = $redisConfig['connection'] ?? 'tcp://127.0.0.1:6379';

        if (is_array($connection) && isset($connection['scheme'])) {
            return $connection;
        }

        if (is_string($connection)) {
            return $this->parseConnectionString($connection);
        }

        return [
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379,
        ];
    }

    /**
     * Parse Redis connection string
     *
     * @param string $connectionString
     * @return array
     */
    protected function parseConnectionString(string $connectionString): array
    {
        $parsed = parse_url($connectionString);

        $config = [
            'scheme' => $parsed['scheme'] ?? 'tcp',
            'host' => $parsed['host'] ?? '127.0.0.1',
            'port' => $parsed['port'] ?? 6379,
        ];

        if (isset($parsed['path']) && $parsed['path'] !== '/') {
            $config['database'] = (int) str_replace('/', '', $parsed['path']);
        }

        if (isset($parsed['user'])) {
            $config['password'] = $parsed['pass'] ?? null;
        }

        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
            if (isset($query['database'])) {
                $config['database'] = (int) $query['database'];
            }
            if (isset($query['password'])) {
                $config['password'] = $query['password'];
            }
        }

        return $config;
    }

    /**
     * Get connection options
     *
     * @return array
     */
    protected function getConnectionOptions(): array
    {
        $redisConfig = config('airbend.connections.redis');
        $params = $redisConfig['options']['parameters'] ?? [];

        $options = [
            'prefix' => $redisConfig['prefix'] ?? 'airbend:',
            'read_write_timeout' => 0, // Infinite timeout for pub/sub
            'persistent' => false, // Don't use persistent for pub/sub
            'exceptions' => true, // Enable exceptions
        ];

        if (!empty($params['database'])) {
            $options['database'] = $params['database'];
        }

        if (!empty($params['password'])) {
            $options['password'] = $params['password'];
        }

        return $options;
    }

    /**
     * Get Redis client instance
     *
     * @return Client
     */
    protected function connection(): Client
    {
        return $this->redis;
    }
}
