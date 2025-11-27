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
     * @var mixed
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
        $this->redis = new \Predis\Client($this->getConnectionConfig());
        $this->pubsubChannel = config('airbend.websocket.pubsub_channel', 'doppar-broadcast');
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
            'channel' => $channel,
            'data' => $event->broadcastWith(),
            'socket_id' => $options['except'] ?? null,
        ];

        try {
            $this->redis->publish($this->pubsubChannel, json_encode($payload));
        } catch (\Exception $e) {
            Log::error("WebSocket broadcast failed: {$e->getMessage()}");
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
