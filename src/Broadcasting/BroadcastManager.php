<?php

namespace Doppar\Airbend\Broadcasting;

use Doppar\Airbend\Broadcasting\Concerns\HandleRedisConnection;
use Doppar\Airbend\Broadcasting\Drivers\WebSocketDriver;
use Doppar\Airbend\Broadcasting\Drivers\NullDriver;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastDriver;
use Phaseolies\Support\Facades\Log;

class BroadcastManager
{
    use HandleRedisConnection;

    /**
     * Broadcasting drivers
     *
     * @var array<string, BroadcastDriver>
     */
    protected array $drivers = [];

    /**
     * Default driver name
     *
     * @var string
     */
    protected string $defaultDriver;

    /**
     * Broadcast to specific recipients only
     *
     * @var bool
     */
    protected bool $toOthers = false;

    /**
     * Socket ID to exclude from broadcast
     *
     * @var string|null
     */
    protected ?string $exceptSocketId = null;

    /**
     * Create a new broadcast manager
     */
    public function __construct()
    {
        $this->defaultDriver = config('airbend.default', 'websocket');
    }

    /**
     * Broadcast event to specified channels
     *
     * @param string|array $channels
     * @param BroadcastEvent $event
     * @return void
     */
    public function channel(string|array $channels, BroadcastEvent $event): void
    {
        // Get channels from event if not explicitly provided
        if (empty($channels)) {
            $channels = $event->broadcastOn();
        }

        // Normalize to array
        $channels = is_array($channels) ? $channels : [$channels];

        $driver = $this->driver();

        // Handle event-level toOthers configuration
        if ($event->isBroadcastingToOthers()) {
            $this->toOthers = true;
        }

        // Handle event-level except socket ID
        if (method_exists($event, 'getExceptSocketId') && $event->getExceptSocketId()) {
            $this->exceptSocketId = $event->getExceptSocketId();
        }

        // Set except socket ID if toOthers is enabled and no explicit socket ID is set
        if ($this->toOthers && !$this->exceptSocketId) {
            $this->exceptSocketId = $this->getCurrentSocketId();
        }

        Log::debug('Broadcasting to channels', [
            'channels' => $channels,
            'event' => get_class($event),
            'event_name' => $event->broadcastAs(),
            'to_others' => $this->toOthers,
            'except_socket' => $this->exceptSocketId,
        ]);

        // Broadcast to each channel separately
        foreach ($channels as $channel) {
            Log::debug('Broadcasting to channel', [
                'channel' => $channel,
                'event' => $event->broadcastAs(),
            ]);

            $driver->broadcast($channel, $event, [
                'except' => $this->exceptSocketId,
                'to_others' => $this->toOthers,
            ]);
        }

        // Reset flags for next broadcast
        $this->reset();
    }

    /**
     * Broadcast to all except current user's socket
     *
     * @return self
     */
    public function toOthers(): self
    {
        $this->toOthers = true;
        $this->exceptSocketId = $this->getCurrentSocketId();

        return $this;
    }

    /**
     * Exclude specific socket ID from broadcast
     *
     * @param string $socketId
     * @return self
     */
    public function except(string $socketId): self
    {
        $this->exceptSocketId = $socketId;
        return $this;
    }

    /**
     * Get current socket ID from request
     *
     * @return string|null
     */
    protected function getCurrentSocketId(): ?string
    {
        $request = request();

        // Check X-Socket-ID header
        if ($request->hasHeader('X-Socket-ID')) {
            return $request->header('X-Socket-ID');
        }

        // Check X-Socket-Id header (alternative)
        if ($request->hasHeader('X-Socket-Id')) {
            return $request->header('X-Socket-Id');
        }

        return null;
    }

    /**
     * Reset broadcast options
     *
     * @return void
     */
    protected function reset(): void
    {
        $this->toOthers = false;
        $this->exceptSocketId = null;
    }

    /**
     * Get or set the driver instance
     *
     * @param string|null $driver
     * @return BroadcastDriver
     */
    public function driver(?string $driver = null): BroadcastDriver
    {
        $driver = $driver ?? $this->defaultDriver;

        if (!isset($this->drivers[$driver])) {
            $this->drivers[$driver] = $this->createDriver($driver);
        }

        return $this->drivers[$driver];
    }

    /**
     * Create a driver instance
     *
     * @param string $driver
     * @return BroadcastDriver
     * @throws \InvalidArgumentException
     */
    protected function createDriver(string $driver): BroadcastDriver
    {
        $config = config("airbend.connections.{$driver}");

        if (!$config) {
            throw new \InvalidArgumentException("Broadcasting driver [{$driver}] is not configured.");
        }

        return match ($config['driver'] ?? $driver) {
            'websocket' => $this->createWebSocketDriver(),
            'null' => new NullDriver(),
            default => throw new \InvalidArgumentException("Driver [{$driver}] is not supported."),
        };
    }

    /**
     * Create WebSocket driver instance
     *
     * @return WebSocketDriver
     */
    protected function createWebSocketDriver(): WebSocketDriver
    {
        return new WebSocketDriver();
    }

    /**
     * Set the default driver
     *
     * @param string $driver
     * @return void
     */
    public function setDefaultDriver(string $driver): void
    {
        $this->defaultDriver = $driver;
    }

    /**
     * Get the default driver name
     *
     * @return string
     */
    public function getDefaultDriver(): string
    {
        return $this->defaultDriver;
    }

    /**
     * Get all registered drivers
     *
     * @return array<string, BroadcastDriver>
     */
    public function getDrivers(): array
    {
        return $this->drivers;
    }

    /**
     * Check if a driver is registered
     *
     * @param string $driver
     * @return bool
     */
    public function hasDriver(string $driver): bool
    {
        return isset($this->drivers[$driver]);
    }

    /**
     * Extend the manager with a custom driver
     *
     * @param string $driver
     * @param \Closure $callback
     * @return void
     */
    public function extend(string $driver, \Closure $callback): void
    {
        $this->drivers[$driver] = $callback($this);
    }

    /**
     * Authenticate a channel subscription
     *
     * @param string $socketId
     * @param string $channel
     * @param array|null $userData
     * @return array
     */
    public function authenticate(string $socketId, string $channel, ?array $userData = null): array
    {
        return $this->driver()->authenticate($socketId, $channel, $userData);
    }

    /**
     * Dynamically call the default driver instance
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->driver()->$method(...$parameters);
    }
}
