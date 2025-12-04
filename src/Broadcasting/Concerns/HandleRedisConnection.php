<?php

namespace Doppar\Airbend\Broadcasting\Concerns;

use Predis\Client;
use Doppar\Airbend\Configuration\ConfigurationManager;
use Doppar\Airbend\Exceptions\RedisConnectionException;

trait HandleRedisConnection
{
    /**
     * Initialize Redis connection
     *
     * @return void
     * @throws RedisConnectionException
     */
    protected function handleRedisConnection(): void
    {
        try {
            $config  = $this->getConnectionConfig();
            $options = $this->getConnectionOptions();

            $this->redis = new Client($config, $options);

            $result = $this->redis->ping();

            if (is_object($result)) {
                $result = (string) $result;
            }

            if ($result !== 'PONG') {
                throw RedisConnectionException::connectionFailed(
                    "Redis ping failed: expected PONG, got " . var_export($result, true)
                );
            }
        } catch (\Exception $e) {
            throw RedisConnectionException::connectionFailed($e->getMessage(), $e);
        }
    }

    /**
     * Build connection config
     *
     * @return array
     */
    protected function getConnectionConfig(): array
    {
        $redisConfig = ConfigurationManager::getRedisConfig();
        $connection  = $redisConfig['connection'] ?? 'redis://127.0.0.1:6379';

        if (is_array($connection) && isset($connection['scheme'])) {
            return $this->normalizeScheme($connection);
        }

        // URL-based
        if (is_string($connection)) {
            $parsed = $this->parseConnectionString($connection);
            return $this->normalizeScheme($parsed);
        }

        // Fallback
        return ['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379];
    }

    /**
     * Parse Redis URL into Predis config array
     *
     * @param string $url
     * @return array
     */
    protected function parseConnectionString(string $url): array
    {
        $parsed = parse_url($url) ?: [];

        $config = [
            'scheme' => $parsed['scheme'] ?? 'tcp',
            'host'   => $parsed['host'] ?? '127.0.0.1',
            'port'   => $parsed['port'] ?? 6379,
        ];

        // Username/password
        if (array_key_exists('pass', $parsed)) {
            $config['password'] = $parsed['pass'];
        }

        // Path database (/0)
        if (isset($parsed['path']) && $parsed['path'] !== '/') {
            $config['database'] = (int) trim($parsed['path'], '/');
        }

        // Query params: database, password, prefix, etc.
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);

            if (isset($query['database'])) {
                $config['database'] = (int) $query['database'];
            }
            if (isset($query['password'])) {
                $config['password'] = $query['password'];
            }
            if (isset($query['prefix'])) {
                $config['prefix'] = $query['prefix'];
            }
        }

        return $config;
    }

    /**
     * Normalize Redis scheme for TLS & legacy names
     *
     * @param array $config
     * @return array
     */
    protected function normalizeScheme(array $config): array
    {
        $scheme = $config['scheme'] ?? 'tcp';

        if (in_array($scheme, ['rediss', 'redis+tls'], true)) {
            $config['scheme'] = 'tls';
        } elseif ($scheme === 'redis') {
            $config['scheme'] = 'tcp';
        }

        return $config;
    }

    /**
     * Build Redis client options merged with config
     *
     * @return array
     */
    protected function getConnectionOptions(): array
    {
        $redisConfig = ConfigurationManager::getRedisConfig();
        $params      = $redisConfig['options']['parameters'] ?? [];

        $options = [
            'prefix'            => $redisConfig['prefix'] ?? '',
            'read_write_timeout' => 0,
            'persistent'        => false,
            'exceptions'        => true,
        ];

        if (isset($redisConfig['prefix'])) {
            $options['prefix'] = $redisConfig['prefix'];
        }

        if (!empty($params['database'])) {
            $options['parameters']['database'] = (int) $params['database'];
        }

        if (!empty($params['password'])) {
            $options['parameters']['password'] = $params['password'];
        }

        return $options;
    }

    /**
     * Remove sensitive entries for safe logging
     *
     * @param array $config
     * @return array
     */
    protected function sanitizeConfig(array $config): array
    {
        if (isset($config['password'])) {
            $config['password'] = '***';
        }

        return $config;
    }

    /**
     * Get Redis client
     *
     * @return Client
     */
    protected function connection(): Client
    {
        return $this->redis;
    }
}
