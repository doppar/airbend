<?php

namespace Doppar\Airbend\Broadcasting\Drivers;

use Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastDriver;
use Doppar\Airbend\Broadcasting\Concerns\HandleRedisConnection;
use Doppar\Airbend\Configuration\ConfigurationManager;
use Doppar\Airbend\Exceptions\RedisConnectionException;

class RedisDriver implements BroadcastDriver
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
     * 
     * @throws RedisConnectionException
     */
    public function __construct()
    {
        try {
            $this->handleRedisConnection();
        } catch (\Exception $e) {
            throw RedisConnectionException::connectionFailed($e->getMessage(), $e);
        }

        $this->pubsubChannel = ConfigurationManager::get('connections.redis.broadcast_channel');
    }

    /**
     * Broadcast an event to a channel
     *
     * @param string $channel
     * @param BroadcastEvent $event
     * @param array<string, mixed> $options
     * @return void
     * @throws RedisConnectionException
     */
    public function broadcast(string $channel, BroadcastEvent $event, array $options = []): void
    {
        if (!$event->shouldBroadcast()) {
            return;
        }

        try {
            // Validate Redis connection
            if ($this->redis === null) {
                throw RedisConnectionException::connectionFailed('Redis connection is not available');
            }

            // Separate channel and event name
            $eventName = $event->broadcastAs();
            $eventData = $event->broadcastWith();

            $payload = [
                'event' => $eventName,
                'channel' => $channel,
                'data' => $eventData,
                'socket_id' => $options['except'] ?? null,
                'timestamp' => time(),
                'message_id' => uniqid('msg_', true),
            ];

            $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

            // Push to Redis queue with error handling
            $result = $this->redis->lpush($this->pubsubChannel, $jsonPayload);

            if ($result === false) {
                throw RedisConnectionException::operationFailed('lpush', 'Failed to push message to Redis queue');
            }
        } catch (\JsonException $e) {
            throw RedisConnectionException::operationFailed(
                'json_encode',
                'Failed to encode broadcast payload: ' . $e->getMessage(),
                $e
            );
        } catch (\Exception $e) {
            if ($e instanceof RedisConnectionException) {
                throw $e;
            }

            throw RedisConnectionException::operationFailed(
                'broadcast',
                $e->getMessage(),
                $e
            );
        }
    }

    /**
     * Authenticate private/presence channel
     *
     * @param string $socketId
     * @param string $channel
     * @param array<string, mixed>|null $userData
     * @return array<string, mixed>
     * @throws \JsonException
     */
    public function authenticate(string $socketId, string $channel, ?array $userData = null): array
    {
        $appKey = ConfigurationManager::appKey();
        $appSecret = ConfigurationManager::appSecret();

        if (str_starts_with($channel, 'presence-')) {
            // Presence channel authentication
            $channelData = json_encode($userData, JSON_THROW_ON_ERROR);
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
