<?php

namespace Doppar\Airbend\Exceptions;

/**
 * Exception thrown when Redis connection or operations fail
 */
class RedisConnectionException extends AirbendException
{
    /**
     * Create exception for connection failure
     *
     * @param string $message
     * @param \Throwable|null $previous
     * @return static
     */
    public static function connectionFailed(string $message, ?\Throwable $previous = null): static
    {
        return new static(
            "Redis connection failed: {$message}",
            2001,
            $previous,
            ['connection_error' => $message]
        );
    }

    /**
     * Create exception for operation failure
     *
     * @param string $operation
     * @param string $message
     * @param \Throwable|null $previous
     * @return static
     */
    public static function operationFailed(string $operation, string $message, ?\Throwable $previous = null): static
    {
        return new static(
            "Redis operation [{$operation}] failed: {$message}",
            2002,
            $previous,
            ['operation' => $operation, 'error' => $message]
        );
    }
}