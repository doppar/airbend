<?php

namespace Doppar\Airbend\Exceptions;

use Exception;

/**
 * Base exception class for all Airbend-related exceptions
 */
class AirbendException extends Exception
{
    /**
     * Additional context data for the exception
     *
     * @var array<string, mixed>
     */
    protected array $context = [];

    /**
     * Create a new Airbend exception
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param array<string, mixed> $context
     */
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get exception context data
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set exception context data
     *
     * @param array<string, mixed> $context
     * @return void
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }
}