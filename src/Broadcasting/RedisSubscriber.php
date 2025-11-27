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
     * Last processed message ID
     *
     * @var string
     */
    protected string $lastId = '0-0';

    /**
     * Timer ID
     *
     * @var int|null
     */
    protected ?int $timerId = null;

    /**
     * Create a new Redis subscriber
     *
     * @param WebSocketHandler $handler
     */
    public function __construct(WebSocketHandler $handler)
    {
        $this->handler = $handler;
        $this->channel = config('airbend.websocket.pubsub_channel', 'doppar-broadcast');
    }

    /**
     * Initialize Redis subscription using polling (non-blocking)
     *
     * @return void
     */
    public function initialize(): void
    {
        try {
            $this->handleRedisConnection();
            
            // Use Redis Streams or polling instead of blocking pub/sub
            // Poll every 100ms for new messages using a list
            $this->timerId = Timer::add(0.1, function () {
                try {
                    // Use RPOP to get messages from a list (non-blocking)
                    $message = $this->redis->rpop($this->channel);
                    
                    if ($message) {
                        $this->handleMessage($message);
                    }
                } catch (\Exception $e) {
                    Log::warning("Redis polling error: " . $e->getMessage());
                }
            });

            Log::info("Redis subscriber initialized (polling mode) on channel: {$this->channel}");
        } catch (\Exception $e) {
            Log::error("Failed to initialize Redis subscriber: " . $e->getMessage());
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
                Log::warning('Invalid broadcast message format', ['payload' => substr($payload, 0, 100)]);
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
        if ($this->timerId) {
            Timer::del($this->timerId);
            $this->timerId = null;
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