<?php

namespace Doppar\Airbend\Broadcasting\Drivers;

use Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastDriver;

class RedisDriver implements BroadcastDriver
{
    /**
     * Redis connection
     *
     * @var mixed
     */
    protected $redis;

    /**
     * Create a new Redis driver
     *
     * @param mixed $redis
     */
    public function __construct($redis)
    {
        $this->redis = $redis;
    }

    /**
     * Broadcast an event to a channel
     *
     * @param string $channel
     * @param BroadcastEvent $event
     * @param array $options
     * @return void
     */
    public function broadcast(string $channel, BroadcastEvent $event, array $options = []): void
    {
        if (!$event->shouldBroadcast()) {
            return;
        }

        $payload = [
            'event' => $event->broadcastAs(),
            'data' => $event->broadcastWith(),
            'socket_id' => $options['except'] ?? null,
        ];

        $this->redis->publish($channel, json_encode($payload));
    }

    /**
     * Authenticate private/presence channel
     *
     * @param string $socketId
     * @param string $channel
     * @param array|null $userData
     * @return array
     */
    public function authenticate(string $socketId, string $channel, ?array $userData = null): array
    {
        $driver = new WebSocketDriver();

        return $driver->authenticate($socketId, $channel, $userData);
    }
}