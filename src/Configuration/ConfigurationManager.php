<?php

namespace Doppar\Airbend\Configuration;

use Doppar\Airbend\Exceptions\BroadcastConfigurationException;

/**
 * Configuration manager for Airbend package
 */
class ConfigurationManager
{
    /**
     * Configuration cache
     *
     * @var array<string, mixed>
     */
    protected static array $cache = [];

    /**
     * Configuration schema for validation
     *
     * @var array<string, array>
     */
    protected static array $schema = [
        'default' => ['type' => 'string', 'required' => true],
        'connections' => ['type' => 'array', 'required' => true],
        'websocket' => [
            'type' => 'array',
            'required' => true,
            'schema' => [
                'host' => ['type' => 'string', 'default' => '127.0.0.1'],
                'port' => ['type' => 'integer', 'default' => 6001, 'min' => 1, 'max' => 65535],
                'ssl' => ['type' => 'boolean', 'default' => false],
                'app_key' => ['type' => 'string', 'default' => 'doppar-app-key'],
                'app_secret' => ['type' => 'string', 'default' => 'doppar-app-secret'],
                'channel' => ['type' => 'string', 'default' => 'doppar-broadcast'],
                'max_connections' => ['type' => 'integer', 'default' => 1000, 'min' => 1],
                'connection_timeout' => ['type' => 'integer', 'default' => 180, 'min' => 1],
                'heartbeat_interval' => ['type' => 'integer', 'default' => 30, 'min' => 1],
                'redis_poll_interval' => ['type' => 'float', 'default' => 0.1, 'min' => 0.01],
            ]
        ]
    ];

    /**
     * Get configuration value with validation and caching
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     * @throws BroadcastConfigurationException
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // Check cache first
        if (array_key_exists($key, static::$cache)) {
            return static::$cache[$key];
        }

        // Handle the framework's config function limitation with array defaults
        $value = config("airbend.{$key}");
        if ($value === null && $default !== null) {
            $value = $default;
        }
        
        // Validate if schema exists for this key
        if (static::hasSchema($key)) {
            $value = static::validateAndNormalize($key, $value);
        }

        // Cache the value
        static::$cache[$key] = $value;

        return $value;
    }

    /**
     * Validate and normalize configuration value
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     * @throws BroadcastConfigurationException
     */
    protected static function validateAndNormalize(string $key, mixed $value): mixed
    {
        $schema = static::getSchema($key);

        // Handle required fields
        if (($schema['required'] ?? false) && $value === null) {
            throw BroadcastConfigurationException::invalidConfigurationValue(
                $key, 
                $value, 
                $schema['type']
            );
        }

        // Use default if value is null
        if ($value === null && isset($schema['default'])) {
            $value = $schema['default'];
        }

        // Type validation
        if ($value !== null && !static::validateType($value, $schema['type'])) {
            throw BroadcastConfigurationException::invalidConfigurationValue(
                $key, 
                $value, 
                $schema['type']
            );
        }

        // Range validation for numeric types
        if (is_numeric($value)) {
            if (isset($schema['min']) && $value < $schema['min']) {
                throw BroadcastConfigurationException::invalidConfigurationValue(
                    $key, 
                    $value, 
                    "minimum value of {$schema['min']}"
                );
            }
            if (isset($schema['max']) && $value > $schema['max']) {
                throw BroadcastConfigurationException::invalidConfigurationValue(
                    $key, 
                    $value, 
                    "maximum value of {$schema['max']}"
                );
            }
        }

        return $value;
    }

    /**
     * Validate value type
     *
     * @param mixed $value
     * @param string $expectedType
     * @return bool
     */
    protected static function validateType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            default => true,
        };
    }

    /**
     * Check if schema exists for key
     *
     * @param string $key
     * @return bool
     */
    protected static function hasSchema(string $key): bool
    {
        return static::getSchema($key) !== null;
    }

    /**
     * Get schema for key (supports nested keys with dots)
     *
     * @param string $key
     * @return array|null
     */
    protected static function getSchema(string $key): ?array
    {
        $keys = explode('.', $key);
        $schema = static::$schema;

        foreach ($keys as $keyPart) {
            if (!isset($schema[$keyPart])) {
                return null;
            }
            $schema = $schema[$keyPart];
        }

        return is_array($schema) ? $schema : null;
    }

    /**
     * Get WebSocket configuration
     *
     * @return array<string, mixed>
     */
    public static function getWebSocketConfig(): array
    {
        return static::get('websocket', []);
    }

    /**
     * Get Redis configuration for WebSocket
     *
     * @return array<string, mixed>
     */
    public static function getRedisConfig(): array
    {
        return static::get('connections.websocket.redis', []);
    }

    /**
     * Get driver configuration
     *
     * @param string $driver
     * @return array<string, mixed>
     * @throws BroadcastConfigurationException
     */
    public static function getDriverConfig(string $driver): array
    {
        $config = static::get("connections.{$driver}");
        
        if (!$config) {
            throw BroadcastConfigurationException::missingDriverConfiguration($driver);
        }

        return $config;
    }

    /**
     * Clear configuration cache
     *
     * @return void
     */
    public static function clearCache(): void
    {
        static::$cache = [];
    }

    /**
     * Validate entire configuration
     *
     * @return array<string, mixed> Validation errors (empty if valid)
     */
    public static function validateConfiguration(): array
    {
        $errors = [];

        try {
            // Validate default driver exists
            $defaultDriver = static::get('default');
            $connections = static::get('connections', []);
            
            if (!isset($connections[$defaultDriver])) {
                $errors['default'] = "Default driver '{$defaultDriver}' is not configured in connections.";
            }

            // Validate WebSocket configuration
            static::getWebSocketConfig();

        } catch (BroadcastConfigurationException $e) {
            $errors[$e->getContext()['key'] ?? 'unknown'] = $e->getMessage();
        }

        return $errors;
    }
}