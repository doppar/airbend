<?php

namespace Doppar\Airbend\Exceptions;

/**
 * Exception thrown when WebSocket operations fail
 */
class WebSocketException extends AirbendException
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
            "WebSocket connection failed: {$message}",
            3001,
            $previous,
            ['connection_error' => $message]
        );
    }

    /**
     * Create exception for authentication failure
     *
     * @param string $channel
     * @param string $reason
     * @return static
     */
    public static function authenticationFailed(string $channel, string $reason): static
    {
        return new static(
            "Authentication failed for channel [{$channel}]: {$reason}",
            3002,
            null,
            ['channel' => $channel, 'reason' => $reason]
        );
    }

    /**
     * Create exception for invalid message format
     *
     * @param string $message
     * @return static
     */
    public static function invalidMessageFormat(string $message): static
    {
        return new static(
            "Invalid WebSocket message format: {$message}",
            3003,
            null,
            ['format_error' => $message]
        );
    }
}