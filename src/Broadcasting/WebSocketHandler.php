<?php

namespace Doppar\Airbend\Broadcasting;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Phaseolies\Support\Facades\Log;
use Doppar\Airbend\Broadcasting\Concerns\HandlesPresence;
use Doppar\Airbend\Broadcasting\Concerns\HandlesChannels;
use Doppar\Airbend\Broadcasting\Concerns\HandlesAuthentication;

class WebSocketHandler implements MessageComponentInterface
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
     * Format: ['channel-name' => [ConnectionInterface, ...]]
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
     * Format: ['private-channel' => [ConnectionInterface, ...]]
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
     * @param ConnectionInterface $conn
     * @return void
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);

        $socketId = $this->generateSocketId();
        $this->clientMetadata[$conn->resourceId] = [
            'socket_id' => $socketId,
            'auth_data' => null,
            'last_heartbeat' => time(),
            'subscribed_channels' => [],
        ];

        // Send connection established event
        $this->sendToClient($conn, [
            'event' => 'doppar:connection_established',
            'data' => json_encode([
                'socket_id' => $socketId,
                'activity_timeout' => 120,
            ]),
        ]);

        Log::info("WebSocket connection opened: {$conn->resourceId} (socket: {$socketId})");
    }

    /**
     * Handle incoming message
     *
     * @param ConnectionInterface $from
     * @param string $msg
     * @return void
     */
    public function onMessage(ConnectionInterface $from, $msg): void
    {
        try {
            $data = json_decode($msg, true);

            if (!isset($data['event'])) {
                $this->sendError($from, 'Missing event field');
                return;
            }

            $event = $data['event'];
            $channel = $data['channel'] ?? null;
            $eventData = $data['data'] ?? [];

            // Update last activity
            $this->clientMetadata[$from->resourceId]['last_heartbeat'] = time();

            // Route the message based on event type
            match ($event) {
                'doppar:subscribe' => $this->handleSubscribe($from, $channel, $eventData),
                'doppar:unsubscribe' => $this->handleUnsubscribe($from, $channel),
                'doppar:ping' => $this->handlePing($from),
                'client-event' => $this->handleClientEvent($from, $channel, $eventData),
                default => $this->handleBroadcast($from, $event, $channel, $eventData),
            };
        } catch (\Exception $e) {
            Log::error("Error processing message: " . $e->getMessage());
            $this->sendError($from, 'Invalid message format');
        }
    }

    /**
     * Handle connection close
     *
     * @param ConnectionInterface $conn
     * @return void
     */
    public function onClose(ConnectionInterface $conn): void
    {
        $this->removeFromAllChannels($conn);
        $this->clients->detach($conn);

        unset($this->clientMetadata[$conn->resourceId]);

        Log::info("WebSocket connection closed: {$conn->resourceId}");
    }

    /**
     * Handle connection error
     *
     * @param ConnectionInterface $conn
     * @param \Exception $e
     * @return void
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        Log::error("WebSocket error on connection {$conn->resourceId}: " . $e->getMessage());

        $conn->close();
    }

    /**
     * Handle channel subscription
     *
     * @param ConnectionInterface $conn
     * @param string|null $channel
     * @param array $data
     * @return void
     */
    protected function handleSubscribe(ConnectionInterface $conn, ?string $channel, array $data): void
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
     * @param ConnectionInterface $conn
     * @param string $channel
     * @return void
     */
    protected function subscribeToPublicChannel(ConnectionInterface $conn, string $channel): void
    {
        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = [];
        }

        $this->channels[$channel][] = $conn;
        $this->clientMetadata[$conn->resourceId]['subscribed_channels'][] = $channel;

        $this->sendToClient($conn, [
            'event' => 'doppar:subscription_succeeded',
            'channel' => $channel,
        ]);

        Log::info("Client {$conn->resourceId} subscribed to public channel: {$channel}");
    }

    /**
     * Handle channel unsubscription
     *
     * @param ConnectionInterface $conn
     * @param string|null $channel
     * @return void
     */
    protected function handleUnsubscribe(ConnectionInterface $conn, ?string $channel): void
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
     * @param ConnectionInterface $conn
     * @return void
     */
    protected function handlePing(ConnectionInterface $conn): void
    {
        $this->sendToClient($conn, [
            'event' => 'doppar:pong',
        ]);
    }

    /**
     * Handle client-triggered events
     *
     * @param ConnectionInterface $from
     * @param string $channel
     * @param array $data
     * @return void
     */
    protected function handleClientEvent(ConnectionInterface $from, string $channel, array $data): void
    {
        // Client events are only allowed on private/presence channels
        if (!str_starts_with($channel, 'private-') && !str_starts_with($channel, 'presence-')) {
            $this->sendError($from, 'Client events only allowed on private/presence channels');
            return;
        }

        // Broadcast to all channel subscribers except sender
        $this->broadcastToChannel($channel, $data, $from->resourceId);
    }

    /**
     * Handle server-side broadcast
     *
     * @param ConnectionInterface $from
     * @param string $event
     * @param string|null $channel
     * @param array $data
     * @return void
     */
    protected function handleBroadcast(ConnectionInterface $from, string $event, ?string $channel, array $data): void
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
     * @param int|null $exceptResourceId
     * @return void
     */
    public function broadcastToChannel(string $channel, array $message, ?int $exceptResourceId = null): void
    {
        $subscribers = $this->channels[$channel] ?? [];

        foreach ($subscribers as $client) {
            if ($exceptResourceId && $client->resourceId === $exceptResourceId) {
                continue;
            }

            $this->sendToClient($client, $message);
        }
    }

    /**
     * Send message to specific client
     *
     * @param ConnectionInterface $conn
     * @param array $message
     * @return void
     */
    public function sendToClient(ConnectionInterface $conn, array $message): void
    {
        $conn->send(json_encode($message));
    }

    /**
     * Send error message to client
     *
     * @param ConnectionInterface $conn
     * @param string $message
     * @return void
     */
    protected function sendError(ConnectionInterface $conn, string $message): void
    {
        $this->sendToClient($conn, [
            'event' => 'doppar:error',
            'data' => json_encode(['message' => $message]),
        ]);
    }

    /**
     * Remove connection from specific channel
     *
     * @param ConnectionInterface $conn
     * @param string $channel
     * @return void
     */
    protected function removeFromChannel(ConnectionInterface $conn, string $channel): void
    {
        if (isset($this->channels[$channel])) {
            $this->channels[$channel] = array_filter(
                $this->channels[$channel],
                fn($c) => $c->resourceId !== $conn->resourceId
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
     * @param ConnectionInterface $conn
     * @return void
     */
    protected function removeFromAllChannels(ConnectionInterface $conn): void
    {
        $subscribedChannels = $this->clientMetadata[$conn->resourceId]['subscribed_channels'] ?? [];

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
            $lastHeartbeat = $this->clientMetadata[$client->resourceId]['last_heartbeat'] ?? 0;

            if ($now - $lastHeartbeat > $timeout) {
                Log::info("Closing stale connection: {$client->resourceId}");
                $client->close();
            }
        }
    }
}