<?php

namespace Doppar\Airbend\Broadcasting;

use Workerman\Timer;
use Phaseolies\Support\Facades\Log;
use Doppar\Airbend\Broadcasting\Concerns\HandleRedisConnection;

class RedisSubscriber
{
    use HandleRedisConnection;

    /**
     * Redis connection
     *
     * @var \Predis\Client
     */
    protected $redis;

    /**
     * WebSocket handler reference
     *
     * @var WebSocketHandler
     */
    protected WebSocketHandler $handler;

    /**
     * Pub/sub channel name
     *
     * @var string
     */
    protected string $pubsubChannel;

    /**
     * Polling interval in seconds
     *
     * @var float
     */
    protected float $pollInterval = 0.1;

    /**
     * Timer ID for polling
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
        $this->pubsubChannel = config('airbend.websocket.channel', 'doppar-broadcast');
    }

    /**
     * Initialize the Redis subscriber in the worker context
     *
     * @return void
     */
    public function initialize(): void
    {
        try {
            $this->handleRedisConnection();
            $this->redis->ping();

            Log::info("Redis subscriber connected successfully");

            $this->startPolling();

            Log::info("Redis subscriber initialized and polling started on channel: {$this->pubsubChannel}");
        } catch (\Exception $e) {
            Log::error("Failed to initialize Redis subscriber: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Start polling Redis for messages
     *
     * @return void
     */
    protected function startPolling(): void
    {
        $this->timerId = Timer::add($this->pollInterval, function () {
            $this->pollMessages();
        });

        Log::debug("Redis polling started with interval: {$this->pollInterval}s");
    }

    /**
     * Poll Redis for new messages
     *
     * @return void
     */
    protected function pollMessages(): void
    {
        try {
            $message = $this->redis->rpop($this->pubsubChannel);

            if ($message) {
                Log::debug("📨 Message received from Redis", [
                    'queue' => $this->pubsubChannel,
                    'message_length' => strlen($message),
                ]);
                $this->processMessage($message);
            }
        } catch (\Exception $e) {
            Log::error("Error polling Redis: " . $e->getMessage());
            $this->reconnect();
        }
    }

    /**
     * Process a broadcast message
     *
     * @param string $message
     * @return void
     */
    protected function processMessage(string $message): void
    {
        try {
            Log::debug("Processing message", ['raw' => substr($message, 0, 200)]);

            $data = json_decode($message, true);

            if (!$data || !isset($data['event'], $data['channel'])) {
                Log::warning("Invalid broadcast message format", ['message' => $message]);
                return;
            }

            $event = $data['event'];
            $channel = $data['channel'];
            $eventData = $data['data'] ?? [];
            $exceptSocketId = $data['socket_id'] ?? null;

            Log::debug("📡 Broadcasting to WebSocket clients", [
                'channel' => $channel,
                'event' => $event,
                'subscriber_count' => count($this->handler->channels[$channel] ?? []),
            ]);

            $exceptConnectionId = null;
            if ($exceptSocketId) {
                $exceptConnectionId = $this->findConnectionIdBySocketId($exceptSocketId);
                Log::debug("Excluding socket", [
                    'socket_id' => $exceptSocketId,
                    'connection_id' => $exceptConnectionId,
                ]);
            }

            // Broadcast to the channel
            $this->handler->broadcastToChannel($channel, [
                'event' => $event,
                'channel' => $channel,
                'data' => is_string($eventData) ? $eventData : json_encode($eventData),
            ], $exceptConnectionId);

            Log::debug("Broadcast message sent", [
                'event' => $event,
                'channel' => $channel,
                'except_socket' => $exceptSocketId,
            ]);
        } catch (\Exception $e) {
            Log::error("Error processing broadcast message: " . $e->getMessage(), [
                'message' => substr($message, 0, 500),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Find connection ID by socket ID
     *
     * @param string $socketId
     * @return int|null
     */
    protected function findConnectionIdBySocketId(string $socketId): ?int
    {
        foreach ($this->handler->clientMetadata as $connectionId => $metadata) {
            if (($metadata['socket_id'] ?? null) === $socketId) {
                return $connectionId;
            }
        }

        return null;
    }

    /**
     * Reconnect to Redis
     *
     * @return void
     */
    protected function reconnect(): void
    {
        try {
            Log::warning("Attempting to reconnect to Redis...");

            $this->handleRedisConnection();

            $this->redis->ping();

            Log::info("Successfully reconnected to Redis");
        } catch (\Exception $e) {
            Log::error("Failed to reconnect to Redis: " . $e->getMessage());
        }
    }

    /**
     * Stop polling and disconnect
     *
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->timerId !== null) {
            Timer::del($this->timerId);
            $this->timerId = null;
            Log::info("Redis polling stopped");
        }

        if ($this->redis) {
            try {
                $this->redis->disconnect();
                Log::info("Disconnected from Redis");
            } catch (\Exception $e) {
                Log::error("Error disconnecting from Redis: " . $e->getMessage());
            }
        }
    }

    /**
     * Get subscription statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        return [
            'channel' => $this->pubsubChannel,
            'poll_interval' => $this->pollInterval,
            'is_polling' => $this->timerId !== null,
        ];
    }
}
