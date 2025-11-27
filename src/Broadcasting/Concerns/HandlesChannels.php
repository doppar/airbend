<?php

namespace Doppar\Airbend\Broadcasting\Concerns;

use Workerman\Connection\TcpConnection;

trait HandlesChannels
{
    /**
     * Get all active channels
     *
     * @return array
     */
    public function getActiveChannels(): array
    {
        return array_keys($this->channels);
    }

    /**
     * Get subscriber count for a channel
     *
     * @param string $channel
     * @return int
     */
    public function getChannelSubscriberCount(string $channel): int
    {
        return count($this->channels[$channel] ?? []);
    }

    /**
     * Check if channel exists
     *
     * @param string $channel
     * @return bool
     */
    public function channelExists(string $channel): bool
    {
        return isset($this->channels[$channel]) && !empty($this->channels[$channel]);
    }

    /**
     * Check if connection is subscribed to channel
     *
     * @param TcpConnection $conn
     * @param string $channel
     * @return bool
     */
    public function isSubscribed(TcpConnection $conn, string $channel): bool
    {
        $subscribedChannels = $this->clientMetadata[$conn->id]['subscribed_channels'] ?? [];

        return in_array($channel, $subscribedChannels);
    }

    /**
     * Get all channels a connection is subscribed to
     *
     * @param TcpConnection $conn
     * @return array
     */
    public function getSubscribedChannels(TcpConnection $conn): array
    {
        return $this->clientMetadata[$conn->id]['subscribed_channels'] ?? [];
    }

    /**
     * Broadcast to multiple channels at once
     *
     * @param array $channels
     * @param array $message
     * @param int|null $exceptConnectionId
     * @return void
     */
    public function broadcastToChannels(array $channels, array $message, ?int $exceptConnectionId = null): void
    {
        foreach ($channels as $channel) {
            $this->broadcastToChannel($channel, $message, $exceptConnectionId);
        }
    }

    /**
     * Get channel statistics
     *
     * @return array
     */
    public function getChannelStats(): array
    {
        $stats = [
            'total_channels' => count($this->channels),
            'total_connections' => $this->clients->count(),
            'channels' => [],
        ];

        foreach ($this->channels as $channel => $subscribers) {
            $type = 'public';
            if (str_starts_with($channel, 'private-')) {
                $type = 'private';
            } elseif (str_starts_with($channel, 'presence-')) {
                $type = 'presence';
            }

            $stats['channels'][$channel] = [
                'type' => $type,
                'subscriber_count' => count($subscribers),
            ];

            if ($type === 'presence') {
                $stats['channels'][$channel]['member_count'] = $this->getPresenceCount($channel);
            }
        }

        return $stats;
    }

    /**
     * Terminate a channel - disconnect all subscribers
     *
     * @param string $channel
     * @param string|null $reason
     * @return void
     */
    public function terminateChannel(string $channel, ?string $reason = null): void
    {
        $subscribers = $this->channels[$channel] ?? [];

        foreach ($subscribers as $client) {
            if ($reason) {
                $this->sendToClient($client, [
                    'event' => 'doppar:channel_terminated',
                    'channel' => $channel,
                    'data' => json_encode(['reason' => $reason]),
                ]);
            }

            $this->removeFromChannel($client, $channel);
        }

        unset($this->channels[$channel]);
        unset($this->presenceChannels[$channel]);
        unset($this->privateChannels[$channel]);
    }
}