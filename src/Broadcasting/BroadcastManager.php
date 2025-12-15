<?php

namespace Doppar\Airbend\Broadcasting;

use Doppar\Airbend\Broadcasting\Drivers\RedisDriver;
use Doppar\Airbend\Broadcasting\Drivers\NullDriver;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastDriver;
use Doppar\Airbend\Broadcasting\Drivers\WorkermanDriver;
use Doppar\Airbend\Configuration\ConfigurationManager;
use Doppar\Airbend\Exceptions\BroadcastConfigurationException;
use Doppar\Airbend\Monitoring\MetricsCollector;
use Phaseolies\Support\Facades\Log;

class BroadcastManager
{
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
     * 
     * @throws BroadcastConfigurationException
     */
    public function __construct()
    {
        $this->defaultDriver = ConfigurationManager::get('default', 'workerman');

        $errors = ConfigurationManager::validateConfiguration();
        if (!empty($errors)) {
            throw new BroadcastConfigurationException(
                'Invalid Airbend configuration: ' . implode(', ', $errors)
            );
        }
    }

    /**
     * Broadcast event to specified channels
     *
     * @param string|array $channels
     * @param BroadcastEvent $event
     * @return void
     * @throws BroadcastConfigurationException
     */
    public function channel(string|array $channels, BroadcastEvent $event): void
    {
        MetricsCollector::startTiming();

        try {
            // Get channels from event if not explicitly provided
            if (empty($channels)) {
                $channels = $event->broadcastOn();
            }

            if (empty($channels)) {
                throw new BroadcastConfigurationException('No channels specified for broadcasting');
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

            // Broadcast to each channel separately
            $successCount = 0;
            foreach ($channels as $channel) {
                try {
                    $driver->broadcast($channel, $event, [
                        'except' => $this->exceptSocketId,
                        'to_others' => $this->toOthers,
                    ]);

                    $successCount++;
                    MetricsCollector::recordChannel('broadcast');
                } catch (\Exception $e) {
                    MetricsCollector::recordError('broadcast');
                    Log::error('Failed to broadcast to channel', [
                        'channel' => $channel,
                        'event' => $event->broadcastAs(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            // Reset flags for next broadcast
            $this->reset();
            MetricsCollector::endTiming('broadcast');
        }
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
     * @throws BroadcastConfigurationException
     */
    protected function createDriver(string $driver): BroadcastDriver
    {
        try {
            $config = ConfigurationManager::getDriverConfig($driver);

            return match ($config['driver'] ?? $driver) {
                'workerman' => new WorkermanDriver(),
                'redis' => new RedisDriver(),
                'null' => new NullDriver(),
                default => throw BroadcastConfigurationException::unsupportedDriver($config['driver'] ?? $driver),
            };
        } catch (BroadcastConfigurationException $e) {
            throw $e;
        }
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
     * Broadcast event using channels defined in the event
     *
     * @param BroadcastEvent $event
     * @return self
     * @throws BroadcastConfigurationException
     */
    public function event(BroadcastEvent $event): self
    {
        $channels = $event->broadcastOn();

        if (empty($channels)) {
            throw new BroadcastConfigurationException(
                'No channels specified in event broadcastOn() method for: ' . get_class($event)
            );
        }

        $this->channel($channels, $event);

        return $this;
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
