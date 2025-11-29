<?php

namespace Doppar\Airbend\Exceptions;

/**
 * Exception thrown when broadcast configuration is invalid or missing
 */
class BroadcastConfigurationException extends AirbendException
{
    /**
     * Create exception for missing driver configuration
     *
     * @param string $driver
     * @return static
     */
    public static function missingDriverConfiguration(string $driver): static
    {
        return new static(
            "Broadcasting driver [{$driver}] is not configured.",
            1001,
            null,
            ['driver' => $driver]
        );
    }

    /**
     * Create exception for unsupported driver
     *
     * @param string $driver
     * @return static
     */
    public static function unsupportedDriver(string $driver): static
    {
        return new static(
            "Broadcasting driver [{$driver}] is not supported.",
            1002,
            null,
            ['driver' => $driver]
        );
    }

    /**
     * Create exception for invalid configuration value
     *
     * @param string $key
     * @param mixed $value
     * @param string $expected
     * @return static
     */
    public static function invalidConfigurationValue(string $key, mixed $value, string $expected): static
    {
        return new static(
            "Invalid configuration value for [{$key}]. Expected {$expected}, got " . gettype($value) . ".",
            1003,
            null,
            ['key' => $key, 'value' => $value, 'expected' => $expected]
        );
    }
}