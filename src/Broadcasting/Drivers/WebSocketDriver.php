<?php

namespace Doppar\Airbend\Broadcasting\Drivers;

use Phaseolies\Support\Facades\Log;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastDriver;
use Doppar\Airbend\Broadcasting\Concerns\HandleRedisConnection;

class WebSocketDriver implements BroadcastDriver
{
    use HandleRedisConnection;

    /**
     * Redis connection
     *
     * @var \Predis\Client
     */
    protected $redis;

    /**
     * Pub/sub channel
     *
     * @var string
     */
    protected string $pubsubChannel;

    /**
     * Create a new WebSocket driver
     */
    public function __construct()
    {
        $this->handleRedisConnection();

        $this->pubsubChannel = config('airbend.websocket.channel', 'doppar-broadcast');

        Log::debug('WebSocketDriver initialized', [
            'channel' => $this->pubsubChannel,
            'note' => 'Using raw Redis connection without prefix',
        ]);
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
            Log::debug('Event should not broadcast, skipping', [
                'event' => get_class($event),
                'channel' => $channel,
            ]);
            return;
        }

        // Separate channel and event name
        $eventName = $event->broadcastAs();
        $eventData = $event->broadcastWith();

        $payload = [
            'event' => $eventName,
            'channel' => $channel,
            'data' => $eventData,
            'socket_id' => $options['except'] ?? null,
        ];

        try {
            // Push to Redis queue
            $result = $this->redis->lpush($this->pubsubChannel, json_encode($payload));

            Log::debug('Event broadcast to Redis', [
                'channel' => $channel,
                'event' => $eventName,
                'data_keys' => array_keys($eventData),
                'socket_id' => $options['except'] ?? 'none',
                'redis_key' => $this->pubsubChannel,
                'queue_length' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error("WebSocket broadcast failed: {$e->getMessage()}", [
                'channel' => $channel,
                'event' => $eventName,
                'trace' => $e->getTraceAsString(),
            ]);
        }
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
        $appKey = config('airbend.websocket.app_key');
        $appSecret = config('airbend.websocket.app_secret');

        if (str_starts_with($channel, 'presence-')) {
            // Presence channel authentication
            $channelData = json_encode($userData);
            $stringToSign = "{$socketId}:{$channel}:{$channelData}";
            $signature = hash_hmac('sha256', $stringToSign, $appSecret);

            return [
                'auth' => "{$appKey}:{$signature}",
                'channel_data' => $channelData,
            ];
        }

        // Private channel authentication
        $stringToSign = "{$socketId}:{$channel}";
        $signature = hash_hmac('sha256', $stringToSign, $appSecret);

        return [
            'auth' => "{$appKey}:{$signature}",
        ];
    }
}
