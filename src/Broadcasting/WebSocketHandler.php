<?php

namespace Doppar\Airbend\Broadcasting;

use Workerman\Connection\TcpConnection;
use Phaseolies\Support\Facades\Log;
use Doppar\Airbend\Broadcasting\Concerns\HandlesPresence;
use Doppar\Airbend\Broadcasting\Concerns\HandlesChannels;
use Doppar\Airbend\Broadcasting\Concerns\HandlesAuthentication;
use Doppar\Airbend\Exceptions\WebSocketException;
use Doppar\Airbend\Configuration\ConfigurationManager;

class WebSocketHandler
{
    use HandlesAuthentication,
        HandlesChannels,
        HandlesPresence;

    /**
     * Connected clients storage
     *
     * @var \SplObjectStorage
     */
    public \SplObjectStorage $clients;

    /**
     * Channel subscriptions
     * Format: ['channel-name' => [TcpConnection, ...]]
     *
     * @var array
     */
    protected array $channels = [];

    /**
     * Presence channel members
     * Format: ['presence-channel' => ['user_id' => ['id', 'info'], ...]]
     *
     * @var array
     */
    protected array $presenceChannels = [];

    /**
     * Client metadata
     * Format: [connectionId => ['socket_id', 'auth_data', 'last_heartbeat']]
     *
     * @var array
     */
    public array $clientMetadata = [];

    /**
     * Create a new WebSocket handler
     */
    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
    }

    /**
     * Handle new connection
     *
     * @param TcpConnection $conn
     * @return void
     */
    public function onOpen(TcpConnection $conn): void
    {
        try {
            $maxConnections = ConfigurationManager::get('websocket.max_connections', 1000);
            if ($this->clients->count() >= $maxConnections) {
                $conn->close();
                return;
            }

            $this->clients->offsetSet($conn, null);

            $socketId = $this->generateSocketId();
            $connectionId = $conn->id;
            $this->clientMetadata[$connectionId] = [
                'socket_id' => $socketId,
                'auth_data' => null,
                'last_heartbeat' => time(),
                'subscribed_channels' => [],
                'connected_at' => time(),
                'remote_address' => $conn->getRemoteAddress() ?? 'unknown',
            ];

            // Send connection established event
            $connectionTimeout = ConfigurationManager::get('websocket.connection_timeout', 180);
            $this->sendToClient($conn, [
                'event' => 'doppar:connection_established',
                'data' => json_encode([
                    'socket_id' => $socketId,
                    'activity_timeout' => $connectionTimeout,
                ], JSON_THROW_ON_ERROR),
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling new WebSocket connection', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                $conn->close();
            } catch (\Exception $closeException) {
                Log::error('Failed to close connection after error', [
                    'error' => $closeException->getMessage(),
                ]);
            }
        }
    }

    /**
     * Maximum accepted size of a single client message in bytes
     *
     * @var int
     */
    public const MAX_MESSAGE_BYTES = 64 * 1024;

    /**
     * Maximum length of a channel name
     *
     * @var int
     */
    public const MAX_CHANNEL_LENGTH = 200;

    /**
     * Maximum channels a single connection may join
     *
     * @var int
     */
    public const MAX_CHANNELS_PER_CONNECTION = 100;

    /**
     * Handle incoming message
     *
     * @param TcpConnection $from
     * @param string $msg
     * @return void
     */
    public function onMessage(TcpConnection $from, string $msg): void
    {
        $fromId = $from->id;
        $metadata = $this->clientMetadata[$fromId] ?? null;

        try {
            if (strlen($msg) > self::MAX_MESSAGE_BYTES) {
                throw WebSocketException::invalidMessageFormat('Message size exceeds 64KB limit');
            }

            $data = json_decode($msg, true, 32, JSON_THROW_ON_ERROR);

            if (!is_array($data) || !isset($data['event']) || !is_string($data['event']) || $data['event'] === '') {
                throw WebSocketException::invalidMessageFormat('Event name must be a non-empty string');
            }

            $event = $data['event'];
            $channel = $data['channel'] ?? null;
            $eventData = $data['data'] ?? [];

            if ($channel !== null && !$this->isValidChannelName($channel)) {
                throw WebSocketException::invalidMessageFormat('Invalid channel name');
            }

            // Pusher-style clients send channel data as a JSON string
            if (is_string($eventData)) {
                $decoded = json_decode($eventData, true);
                $eventData = is_array($decoded) ? $decoded : [];
            }

            if (!is_array($eventData)) {
                $eventData = [];
            }

            if ($metadata) {
                $this->clientMetadata[$fromId]['last_heartbeat'] = time();
            }

            match ($event) {
                'doppar:subscribe' => $this->handleSubscribe($from, $channel, $eventData),
                'doppar:unsubscribe' => $this->handleUnsubscribe($from, $channel),
                'doppar:ping' => $this->handlePing($from),
                'client-event' => $this->handleClientEvent($from, $channel, $eventData),
                default => throw WebSocketException::invalidMessageFormat('Unknown event'),
            };
        } catch (\JsonException $e) {
            Log::warning('Invalid JSON message received', [
                'connection_id' => $fromId,
                'socket_id' => $metadata['socket_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'message_preview' => substr($msg, 0, 100),
            ]);
            $this->sendError($from, 'Invalid JSON format');
        } catch (WebSocketException $e) {
            Log::warning('WebSocket message error', [
                'connection_id' => $fromId,
                'socket_id' => $metadata['socket_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            $this->sendError($from, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Unexpected error handling WebSocket message', [
                'connection_id' => $fromId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendError($from, 'Internal server error');
        }
    }

    /**
     * Determine whether a value is an acceptable channel name
     *
     * @param mixed $channel
     * @return bool
     */
    protected function isValidChannelName(mixed $channel): bool
    {
        return is_string($channel)
            && $channel !== ''
            && strlen($channel) <= self::MAX_CHANNEL_LENGTH
            && preg_match('/^[A-Za-z0-9_\-=@,.;:]+$/', $channel) === 1;
    }

    /**
     * Handle connection close
     *
     * @param TcpConnection $conn
     * @return void
     */
    public function onClose(TcpConnection $conn): void
    {
        $connectionId = $conn->id;
        $metadata = $this->clientMetadata[$connectionId] ?? null;

        try {
            $this->removeFromAllChannels($conn);
            $this->clients->offsetUnset($conn);
            unset($this->clientMetadata[$connectionId]);
        } catch (\Exception $e) {
            Log::error('Error during connection close cleanup', [
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle connection error
     *
     * @param TcpConnection $conn
     * @param int $code
     * @param string $msg
     * @return void
     */
    public function onError(TcpConnection $conn, int $code, string $msg): void
    {
        $connectionId = $conn->id;

        try {
            $conn->close();
        } catch (\Exception $e) {
            Log::error('Failed to close connection after error', [
                'connection_id' => $connectionId,
                'close_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle channel subscription
     *
     * @param TcpConnection $conn
     * @param string|null $channel
     * @param array $data
     * @return void
     */
    protected function handleSubscribe(TcpConnection $conn, ?string $channel, array $data): void
    {
        if ($channel === null) {
            $this->sendError($conn, 'Channel name required');
            return;
        }

        if (in_array($channel, $this->clientMetadata[$conn->id]['subscribed_channels'] ?? [], true)) {
            $this->sendToClient($conn, [
                'event' => 'doppar:subscription_succeeded',
                'channel' => $channel,
            ]);
            return;
        }

        if (count($this->clientMetadata[$conn->id]['subscribed_channels'] ?? []) >= self::MAX_CHANNELS_PER_CONNECTION) {
            $this->sendError($conn, 'Too many channel subscriptions');
            return;
        }

        // Handle different channel types
        if (str_starts_with($channel, 'private-')) {
            $this->subscribeToPrivateChannel($conn, $channel, $data);
        } elseif (str_starts_with($channel, 'presence-')) {
            $this->subscribeToPresenceChannel($conn, $channel, $data);
        } else {
            $this->subscribeToPublicChannel($conn, $channel);
        }
    }

    /**
     * Subscribe to public channel
     *
     * @param TcpConnection $conn
     * @param string $channel
     * @return void
     */
    protected function subscribeToPublicChannel(TcpConnection $conn, string $channel): void
    {
        $this->attachToChannel($conn, $channel);

        $this->sendToClient($conn, [
            'event' => 'doppar:subscription_succeeded',
            'channel' => $channel,
        ]);
    }

    /**
     * Handle channel unsubscription
     *
     * @param TcpConnection $conn
     * @param string|null $channel
     * @return void
     */
    protected function handleUnsubscribe(TcpConnection $conn, ?string $channel): void
    {
        if ($channel === null || !in_array($channel, $this->clientMetadata[$conn->id]['subscribed_channels'] ?? [], true)) {
            return;
        }

        $this->removeFromChannel($conn, $channel);

        $this->sendToClient($conn, [
            'event' => 'doppar:unsubscribe_succeeded',
            'channel' => $channel,
        ]);
    }

    /**
     * Handle ping/pong for keepalive
     *
     * @param TcpConnection $conn
     * @return void
     */
    protected function handlePing(TcpConnection $conn): void
    {
        $this->sendToClient($conn, [
            'event' => 'doppar:pong',
        ]);
    }

    /**
     * Handle client-triggered events
     *
     * @param TcpConnection $from
     * @param string|null $channel
     * @param array $data
     * @return void
     */
    protected function handleClientEvent(TcpConnection $from, ?string $channel, array $data): void
    {
        if ($channel === null) {
            $this->sendError($from, 'Channel name required');
            return;
        }

        if (!str_starts_with($channel, 'private-') && !str_starts_with($channel, 'presence-')) {
            $this->sendError($from, 'Client events only allowed on private/presence channels');
            return;
        }

        if (!in_array($channel, $this->clientMetadata[$from->id]['subscribed_channels'] ?? [], true)) {
            $this->sendError($from, 'You must be subscribed to the channel to send client events');
            return;
        }

        $innerEvent = $data['event'] ?? null;

        if (!is_string($innerEvent) || !str_starts_with($innerEvent, 'client-') || $innerEvent === 'client-event') {
            $this->sendError($from, 'Client event names must start with "client-"');
            return;
        }

        $payload = $data['data'] ?? [];

        $this->broadcastToChannel($channel, [
            'event' => $innerEvent,
            'channel' => $channel,
            'data' => is_string($payload) ? $payload : json_encode($payload),
        ], $from->id);
    }

    /**
     * Broadcast message to all clients in a channel
     *
     * @param string $channel
     * @param array $message
     * @param int|null $exceptConnectionId
     * @return void
     */
    public function broadcastToChannel(string $channel, array $message, ?int $exceptConnectionId = null): void
    {
        $subscribers = $this->channels[$channel] ?? [];

        foreach ($subscribers as $client) {
            $clientId = $client->id;

            if ($exceptConnectionId && $clientId === $exceptConnectionId) {
                continue;
            }

            $this->sendToClient($client, $message);
        }
    }

    /**
     * Register a connection as a subscriber of a channel
     *
     * @param TcpConnection $conn
     * @param string $channel
     * @return void
     */
    protected function attachToChannel(TcpConnection $conn, string $channel): void
    {
        foreach ($this->channels[$channel] ?? [] as $existing) {
            if ($existing->id === $conn->id) {
                return;
            }
        }

        $this->channels[$channel][] = $conn;
        $this->clientMetadata[$conn->id]['subscribed_channels'][] = $channel;
    }

    /**
     * Send message to specific client
     *
     * @param TcpConnection $conn
     * @param array $message
     * @return void
     */
    public function sendToClient(TcpConnection $conn, array $message): void
    {
        $encoded = json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded === false) {
            Log::warning('Failed to encode outgoing WebSocket message', ['error' => json_last_error_msg()]);
            return;
        }

        $conn->send($encoded);
    }

    /**
     * Send error message to client
     *
     * @param TcpConnection $conn
     * @param string $message
     * @return void
     */
    protected function sendError(TcpConnection $conn, string $message): void
    {
        $this->sendToClient($conn, [
            'event' => 'doppar:error',
            'data' => json_encode(['message' => $message]),
        ]);
    }

    /**
     * Remove connection from specific channel
     *
     * @param TcpConnection $conn
     * @param string $channel
     * @return void
     */
    protected function removeFromChannel(TcpConnection $conn, string $channel): void
    {
        if (isset($this->clientMetadata[$conn->id]['subscribed_channels'])) {
            $this->clientMetadata[$conn->id]['subscribed_channels'] = array_values(array_diff(
                $this->clientMetadata[$conn->id]['subscribed_channels'],
                [$channel]
            ));
        }

        if (isset($this->channels[$channel])) {
            $this->channels[$channel] = array_filter(
                $this->channels[$channel],
                fn($c) => $c->id !== $conn->id
            );

            if (empty($this->channels[$channel])) {
                unset($this->channels[$channel]);
            }
        }

        // Remove from presence if applicable
        if (str_starts_with($channel, 'presence-')) {
            $this->removeFromPresenceChannel($conn, $channel);
        }
    }

    /**
     * Remove connection from all channels
     *
     * @param TcpConnection $conn
     * @return void
     */
    protected function removeFromAllChannels(TcpConnection $conn): void
    {
        $connectionId = $conn->id;
        $subscribedChannels = $this->clientMetadata[$connectionId]['subscribed_channels'] ?? [];

        foreach ($subscribedChannels as $channel) {
            $this->removeFromChannel($conn, $channel);
        }
    }

    /**
     * Generate unique socket ID
     *
     * @return string
     */
    protected function generateSocketId(): string
    {
        return uniqid('', true) . '.' . random_int(1000, 9999);
    }

    /**
     * Send heartbeat to all connected clients
     *
     * @return void
     */
    public function sendHeartbeat(): void
    {
        foreach ($this->clients as $client) {
            $this->sendToClient($client, [
                'event' => 'doppar:heartbeat',
            ]);
        }
    }

    /**
     * Clean up stale connections
     *
     * @return void
     */
    public function cleanupStaleConnections(): void
    {
        $timeout = (int) ConfigurationManager::get('websocket.connection_timeout', 180);
        $now = time();

        foreach ($this->clients as $client) {
            $clientId = $client->id;
            $lastHeartbeat = $this->clientMetadata[$clientId]['last_heartbeat'] ?? 0;

            if ($now - $lastHeartbeat > $timeout) {
                $client->close();
            }
        }
    }
}
