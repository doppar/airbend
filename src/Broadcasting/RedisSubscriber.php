<?php

namespace Doppar\Airbend\Broadcasting;

use Phaseolies\Support\Facades\Log;
use Workerman\Timer;
use Doppar\Airbend\Broadcasting\WebSocketHandler;
use Doppar\Airbend\Broadcasting\Concerns\HandleRedisConnection;

class RedisSubscriber
{
    use HandleRedisConnection;

    /**
     * Redis connection
     *
     * @var mixed
     */
    protected $redis;

    /**
     * WebSocket handler
     *
     * @var WebSocketHandler
     */
    protected WebSocketHandler $handler;

    /**
     * Pub/sub channel name
     *
     * @var string
     */
    protected string $channel;

    /**
     * Redis pub/sub loop instance
     *
     * @var mixed
     */
    protected $pubsub;

    /**
     * Create a new Redis subscriber
     *
     * @param WebSocketHandler $handler
     */
    public function __construct(WebSocketHandler $handler)
    {
        $this->handler = $handler;
        $this->channel = config('airbend.websocket.pubsub_channel', 'doppar-broadcast');

        $this->connect();
    }

    /**
     * Connect to Redis and subscribe
     *
     * @return void
     */
    protected function connect(): void
    {
        $this->handleRedisConnection();

        // Once connected, subscribe to the pub/sub channel so that
        // WebSocket clients receive broadcast events.
        $this->subscribe();
    }

    /**
     * Subscribe to the broadcast channel
     *
     * @return void
     */
    protected function subscribe(): void
    {
        try {
            $this->pubsub = $this->redis->pubSubLoop();

            $this->pubsub->subscribe($this->channel);

            // Periodically process Redis messages using Workerman's Timer
            Timer::add(0.01, function () {
                if ($this->pubsub) {
                    try {
                        $message = $this->pubsub->current();
                        if ($message && $message->kind === 'message') {
                            $this->handleMessage($message->payload);
                        }
                        $this->pubsub->next();
                    } catch (\Exception $e) {
                        Log::error("Error processing Redis message: " . $e->getMessage());
                    }
                }
            });

            Log::info("Subscribed to Redis pub/sub channel: {$this->channel}");
        } catch (\Exception $e) {
            Log::error("Failed to subscribe to Redis channel: " . $e->getMessage());
            // Attempt to reconnect after 5 seconds
            Timer::add(5, function () {
                $this->reconnect();
            });
        }
    }

    /**
     * Reconnect to Redis
     *
     * @return void
     */
    protected function reconnect(): void
    {
        Log::info('Attempting to reconnect to Redis...');

        try {
            $this->disconnect();
            $this->connect();
        } catch (\Exception $e) {
            Log::error("Reconnection failed: " . $e->getMessage());
            // Schedule another reconnection attempt
            Timer::add(5, function () {
                $this->reconnect();
            });
        }
    }

    /**
     * Handle incoming broadcast message
     *
     * @param string $payload
     * @return void
     */
    protected function handleMessage(string $payload): void
    {
        try {
            $data = json_decode($payload, true);

            if (!isset($data['event'], $data['channel'])) {
                Log::warning('Invalid broadcast message format', ['payload' => $payload]);
                return;
            }

            $channel = $data['channel'];
            $event = $data['event'];
            $eventData = $data['data'] ?? [];
            $exceptSocketId = $data['socket_id'] ?? null;

            // Prepare message for WebSocket clients
            $message = [
                'event' => $event,
                'channel' => $channel,
                'data' => is_string($eventData) ? $eventData : json_encode($eventData),
            ];

            // Broadcast to channel subscribers
            $this->handler->broadcastToChannel(
                $channel,
                $message,
                $exceptSocketId ? $this->getConnectionIdBySocketId($exceptSocketId) : null
            );

            Log::debug("Broadcast sent to channel {$channel}: {$event}");
        } catch (\Exception $e) {
            Log::error("Error handling broadcast message: " . $e->getMessage());
        }
    }

    /**
     * Get connection ID by socket ID
     *
     * @param string $socketId
     * @return int|null
     */
    protected function getConnectionIdBySocketId(string $socketId): ?int
    {
        // Search through client metadata to find matching socket ID
        foreach ($this->handler->clientMetadata as $connectionId => $metadata) {
            if (($metadata['socket_id'] ?? null) === $socketId) {
                return $connectionId;
            }
        }

        return null;
    }

    /**
     * Disconnect from Redis
     *
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->pubsub) {
            try {
                $this->pubsub->unsubscribe();
                $this->pubsub = null;
            } catch (\Exception $e) {
                Log::error("Error unsubscribing from Redis: " . $e->getMessage());
            }
        }

        if (isset($this->redis)) {
            try {
                $this->redis->disconnect();
                Log::info('Redis subscriber disconnected');
            } catch (\Exception $e) {
                Log::error('Error disconnecting Redis: ' . $e->getMessage());
            }
        }
    }
}