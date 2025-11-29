<?php

namespace Doppar\Airbend\Broadcasting;

use Workerman\Connection\TcpConnection;
use Phaseolies\Support\Facades\Log;
use Doppar\Airbend\Broadcasting\Concerns\HandlesPresence;
use Doppar\Airbend\Broadcasting\Concerns\HandlesChannels;
use Doppar\Airbend\Broadcasting\Concerns\HandlesAuthentication;
use Doppar\Airbend\Exceptions\WebSocketException;
use Doppar\Airbend\Monitoring\MetricsCollector;
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
     * Private channel subscriptions with auth
     * Format: ['private-channel' => [TcpConnection, ...]]
     *
     * @var array
     */
    protected array $privateChannels = [];

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
                Log::warning('Maximum connections reached, rejecting new connection');
                $conn->close();
                MetricsCollector::recordConnection('failed');
                return;
            }

            $this->clients->attach($conn);
            MetricsCollector::recordConnection('connect');

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

            Log::info('WebSocket connection opened', [
                'connection_id' => $connectionId,
                'socket_id' => $socketId,
                'remote_address' => $this->clientMetadata[$connectionId]['remote_address'],
                'total_connections' => $this->clients->count(),
            ]);

        } catch (\Exception $e) {
            MetricsCollector::recordError('connection');
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
            MetricsCollector::recordMessage('received');
            MetricsCollector::startTiming();
            
            // Validate message size
            if (strlen($msg) > 64 * 1024) { // 64KB limit
                throw WebSocketException::invalidMessageFormat('Message size exceeds 64KB limit');
            }

            $data = json_decode($msg, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data) || !isset($data['event'])) {
                throw WebSocketException::invalidMessageFormat('Missing or invalid event field');
            }

            $event = $data['event'];
            $channel = $data['channel'] ?? null;
            $eventData = $data['data'] ?? [];

            // Validate event name
            if (!is_string($event) || empty($event)) {
                throw WebSocketException::invalidMessageFormat('Event name must be a non-empty string');
            }

            // Update last activity
            if ($metadata) {
                $this->clientMetadata[$fromId]['last_heartbeat'] = time();
            }

            Log::debug('WebSocket message received', [
                'connection_id' => $fromId,
                'socket_id' => $metadata['socket_id'] ?? 'unknown',
                'event' => $event,
                'channel' => $channel,
                'data_size' => strlen($msg),
            ]);

            // Route the message based on event type
            match ($event) {
                'doppar:subscribe' => $this->handleSubscribe($from, $channel, $eventData),
                'doppar:unsubscribe' => $this->handleUnsubscribe($from, $channel),
                'doppar:ping' => $this->handlePing($from),
                'client-event' => $this->handleClientEvent($from, $channel, $eventData),
                default => $this->handleBroadcast($from, $event, $channel, $eventData),
            };
            
        } catch (\JsonException $e) {
            MetricsCollector::recordError('connection');
            Log::warning('Invalid JSON message received', [
                'connection_id' => $fromId,
                'socket_id' => $metadata['socket_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'message_preview' => substr($msg, 0, 100),
            ]);
            $this->sendError($from, 'Invalid JSON format');
            
        } catch (WebSocketException $e) {
            MetricsCollector::recordError('connection');
            Log::warning('WebSocket message error', [
                'connection_id' => $fromId,
                'socket_id' => $metadata['socket_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            $this->sendError($from, $e->getMessage());
        }

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
            $this->clients->detach($conn);
            MetricsCollector::recordConnection('disconnect');

            if ($metadata) {
                $connectionDuration = time() - ($metadata['connected_at'] ?? time());
                Log::info('WebSocket connection closed', [
                    'connection_id' => $connectionId,
                    'socket_id' => $metadata['socket_id'] ?? 'unknown',
                    'duration_seconds' => $connectionDuration,
                    'channels_count' => count($metadata['subscribed_channels'] ?? []),
                    'total_connections' => $this->clients->count(),
                ]);
            } else {
                Log::info('WebSocket connection closed (no metadata)', [
                    'connection_id' => $connectionId,
                ]);
            }

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
        $metadata = $this->clientMetadata[$connectionId] ?? null;
        
        MetricsCollector::recordError('connection');
        
        Log::error('WebSocket connection error', [
            'connection_id' => $connectionId,
            'socket_id' => $metadata['socket_id'] ?? 'unknown',
            'error_code' => $code,
            'error_message' => $msg,
            'remote_address' => $metadata['remote_address'] ?? 'unknown',
        ]);

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
        if (!$channel) {
            $this->sendError($conn, 'Channel name required');
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
        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = [];
        }

        $this->channels[$channel][] = $conn;
        $connectionId = $conn->id;
        $this->clientMetadata[$connectionId]['subscribed_channels'][] = $channel;

        $this->sendToClient($conn, [
            'event' => 'doppar:subscription_succeeded',
            'channel' => $channel,
        ]);

        Log::info("Client {$connectionId} subscribed to public channel: {$channel}");
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
        if (!$channel) {
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
     * @param string $channel
     * @param array $data
     * @return void
     */
    protected function handleClientEvent(TcpConnection $from, string $channel, array $data): void
    {
        // Client events are only allowed on private/presence channels
        if (!str_starts_with($channel, 'private-') && !str_starts_with($channel, 'presence-')) {
            $this->sendError($from, 'Client events only allowed on private/presence channels');
            return;
        }

        // Broadcast to all channel subscribers except sender
        $this->broadcastToChannel($channel, $data, $from->id);
    }

    /**
     * Handle server-side broadcast
     *
     * @param TcpConnection $from
     * @param string $event
     * @param string|null $channel
     * @param array $data
     * @return void
     */
    protected function handleBroadcast(TcpConnection $from, string $event, ?string $channel, array $data): void
    {
        if ($channel) {
            $this->broadcastToChannel($channel, [
                'event' => $event,
                'channel' => $channel,
                'data' => $data,
            ]);
        }
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
     * Send message to specific client
     *
     * @param TcpConnection $conn
     * @param array $message
     * @return void
     */
    public function sendToClient(TcpConnection $conn, array $message): void
    {
        $conn->send(json_encode($message));
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
        $timeout = 180; // 3 minutes
        $now = time();

        foreach ($this->clients as $client) {
            $clientId = $client->id;
            $lastHeartbeat = $this->clientMetadata[$clientId]['last_heartbeat'] ?? 0;

            if ($now - $lastHeartbeat > $timeout) {
                Log::info("Closing stale connection: {$clientId}");
                $client->close();
            }
        }
    }
}