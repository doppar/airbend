<?php

namespace Doppar\Airbend\Broadcasting\Concerns;

use Predis\Client;
use Phaseolies\Support\Facades\Log;
use Doppar\Airbend\Configuration\ConfigurationManager;
use Doppar\Airbend\Exceptions\RedisConnectionException;

trait HandleRedisConnection
{
    /**
     * Connect to Redis
     *
     * @return void
     * @throws RedisConnectionException
     */
    protected function handleRedisConnection(): void
    {
        try {
            $config = $this->getConnectionConfig();
            $options = $this->getConnectionOptions();

            $this->redis = new Client($config, $options);

            // Test the connection
            $result = $this->redis->ping();

            // Predis may return different types: string 'PONG' or Status object
            if (is_object($result)) {
                $result = (string) $result;
            }

            if ($result !== 'PONG') {
                throw RedisConnectionException::connectionFailed('Redis ping failed: expected PONG, got ' . var_export($result, true));
            }

            Log::info('Redis connection established successfully', [
                'host' => $config['host'] ?? 'unknown',
                'port' => $config['port'] ?? 'unknown',
                'database' => $config['database'] ?? 0,
            ]);
        } catch (RedisConnectionException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to connect to Redis', [
                'error' => $e->getMessage(),
                'config' => $this->sanitizeConfig($config ?? []),
            ]);
            throw RedisConnectionException::connectionFailed($e->getMessage(), $e);
        }
    }

    /**
     * Get connection config
     *
     * @return array<string, mixed>
     */
    protected function getConnectionConfig(): array
    {
        $redisConfig = ConfigurationManager::getRedisConfig();
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
     * @return array<string, mixed>
     */
    protected function getConnectionOptions(): array
    {
        // Use the Redis configuration attached to the websocket connection
        $redisConfig = ConfigurationManager::getRedisConfig();
        $params = $redisConfig['options']['parameters'] ?? [];

        $options = [
            'prefix' => $redisConfig['prefix'] ?? '',
            'read_write_timeout' => 0,
            'persistent' => false,
            'exceptions' => true,
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
     * Sanitize configuration for logging (remove sensitive data)
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    protected function sanitizeConfig(array $config): array
    {
        $sanitized = $config;

        // Remove or mask sensitive information
        if (isset($sanitized['password'])) {
            $sanitized['password'] = '***';
        }

        return $sanitized;
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
