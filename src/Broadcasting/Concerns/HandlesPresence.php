<?php

namespace Doppar\Airbend\Broadcasting\Concerns;

use Workerman\Connection\TcpConnection;
use Phaseolies\Support\Facades\Log;

trait HandlesPresence
{
    /**
     * Subscribe to a presence channel
     *
     * @param TcpConnection $conn
     * @param string $channel
     * @param array $authData
     * @return void
     */
    protected function subscribeToPresenceChannel(TcpConnection $conn, string $channel, array $authData): void
    {
        $userData = $this->authenticatePresenceChannel($conn, $channel, $authData);

        if (!$userData) {
            $this->sendError($conn, 'Authentication failed for presence channel');
            Log::warning("Presence channel auth failed for {$channel}", [
                'connection_id' => $conn->id
            ]);
            return;
        }

        // Initialize presence channel if needed
        if (!isset($this->presenceChannels[$channel])) {
            $this->presenceChannels[$channel] = [];
            Log::info("Presence channel created: {$channel}");
        }

        $userId = $userData['user_id'];
        $isNewMember = !isset($this->presenceChannels[$channel][$userId]);

        // Add user to presence channel
        if (!isset($this->presenceChannels[$channel][$userId])) {
            $this->presenceChannels[$channel][$userId] = [
                'id' => $userId,
                'info' => $userData['user_info'] ?? [],
                'connections' => [],
            ];
        }

        $this->presenceChannels[$channel][$userId]['connections'][] = $conn->id;

        // Add to regular channel subscriptions
        if (!isset($this->channels[$channel])) {
            $this->channels[$channel] = [];
        }
        $this->channels[$channel][] = $conn;
        $this->clientMetadata[$conn->id]['subscribed_channels'][] = $channel;
        $this->clientMetadata[$conn->id]['presence_user_id'] = $userId;
        $this->clientMetadata[$conn->id]['presence_channel'] = $channel;

        // Send subscription success with current members
        $presenceData = [
            'presence' => [
                'ids' => array_keys($this->presenceChannels[$channel]),
                'hash' => $this->getPresenceHash($channel),
                'count' => count($this->presenceChannels[$channel]),
            ],
        ];

        $this->sendToClient($conn, [
            'event' => 'doppar:subscription_succeeded',
            'channel' => $channel,
            'data' => json_encode($presenceData),
        ]);

        // Notify other members if this is a new member
        if ($isNewMember) {
            $memberData = [
                'user_id' => $userId,
                'user_info' => $userData['user_info'] ?? [],
            ];

            $this->broadcastToChannel($channel, [
                'event' => 'doppar:member_added',
                'channel' => $channel,
                'data' => json_encode($memberData),
            ], $conn->id);

            Log::info("New member joined presence channel", [
                'channel' => $channel,
                'user_id' => $userId,
                'connection_id' => $conn->id,
                'total_members' => count($this->presenceChannels[$channel])
            ]);
        } else {
            Log::info("Existing member reconnected to presence channel", [
                'channel' => $channel,
                'user_id' => $userId,
                'connection_id' => $conn->id,
                'connections' => count($this->presenceChannels[$channel][$userId]['connections'])
            ]);
        }
    }

    /**
     * Remove connection from presence channel
     *
     * @param TcpConnection $conn
     * @param string $channel
     * @return void
     */
    protected function removeFromPresenceChannel(TcpConnection $conn, string $channel): void
    {
        if (!isset($this->presenceChannels[$channel])) {
            return;
        }

        $userId = $this->clientMetadata[$conn->id]['presence_user_id'] ?? null;

        if (!$userId || !isset($this->presenceChannels[$channel][$userId])) {
            return;
        }

        // Remove this connection from user's connection list
        $connections = &$this->presenceChannels[$channel][$userId]['connections'];
        $connections = array_filter($connections, fn($id) => $id !== $conn->id);

        // If user has no more connections, remove them from presence
        if (empty($connections)) {
            $userInfo = $this->presenceChannels[$channel][$userId]['info'];
            unset($this->presenceChannels[$channel][$userId]);

            // Notify other members
            $this->broadcastToChannel($channel, [
                'event' => 'doppar:member_removed',
                'channel' => $channel,
                'data' => json_encode([
                    'user_id' => $userId,
                ]),
            ]);

            Log::info("Member left presence channel", [
                'channel' => $channel,
                'user_id' => $userId,
                'remaining_members' => count($this->presenceChannels[$channel])
            ]);
        }

        // Clean up empty presence channels
        if (empty($this->presenceChannels[$channel])) {
            unset($this->presenceChannels[$channel]);
            Log::info("Presence channel removed (no members): {$channel}");
        }
    }

    /**
     * Get presence hash for a channel
     *
     * @param string $channel
     * @return array
     */
    protected function getPresenceHash(string $channel): array
    {
        $members = $this->presenceChannels[$channel] ?? [];
        $hash = [];

        foreach ($members as $userId => $data) {
            $hash[$userId] = $data['info'];
        }

        return $hash;
    }

    /**
     * Get member count for presence channel
     *
     * @param string $channel
     * @return int
     */
    public function getPresenceCount(string $channel): int
    {
        return count($this->presenceChannels[$channel] ?? []);
    }

    /**
     * Get all members in a presence channel
     *
     * @param string $channel
     * @return array
     */
    public function getPresenceMembers(string $channel): array
    {
        return array_keys($this->presenceChannels[$channel] ?? []);
    }

    /**
     * Get detailed member information
     *
     * @param string $channel
     * @return array
     */
    public function getPresenceMembersDetailed(string $channel): array
    {
        $members = $this->presenceChannels[$channel] ?? [];
        $result = [];

        foreach ($members as $userId => $data) {
            $result[] = [
                'user_id' => $userId,
                'user_info' => $data['info'],
                'connections' => count($data['connections']),
            ];
        }

        return $result;
    }

    /**
     * Check if user is in presence channel
     *
     * @param string $channel
     * @param string|int $userId
     * @return bool
     */
    public function isUserPresent(string $channel, string|int $userId): bool
    {
        return isset($this->presenceChannels[$channel][$userId]);
    }

    /**
     * Get user info from presence channel
     *
     * @param string $channel
     * @param string|int $userId
     * @return array|null
     */
    public function getPresenceMemberInfo(string $channel, string|int $userId): ?array
    {
        return $this->presenceChannels[$channel][$userId]['info'] ?? null;
    }

    /**
     * Update user info in presence channel
     *
     * @param string $channel
     * @param string|int $userId
     * @param array $newInfo
     * @return void
     */
    public function updatePresenceMemberInfo(string $channel, string|int $userId, array $newInfo): void
    {
        if (!isset($this->presenceChannels[$channel][$userId])) {
            return;
        }

        $this->presenceChannels[$channel][$userId]['info'] = array_merge(
            $this->presenceChannels[$channel][$userId]['info'],
            $newInfo
        );

        // Broadcast update to all members
        $this->broadcastToChannel($channel, [
            'event' => 'doppar:member_updated',
            'channel' => $channel,
            'data' => json_encode([
                'user_id' => $userId,
                'user_info' => $this->presenceChannels[$channel][$userId]['info'],
            ]),
        ]);

        Log::info("Member info updated in presence channel", [
            'channel' => $channel,
            'user_id' => $userId
        ]);
    }

    /**
     * Get all presence channels
     *
     * @return array
     */
    public function getAllPresenceChannels(): array
    {
        return array_keys($this->presenceChannels);
    }

    /**
     * Get presence channel statistics
     *
     * @return array
     */
    public function getPresenceStats(): array
    {
        $stats = [
            'total_presence_channels' => count($this->presenceChannels),
            'total_unique_members' => 0,
            'total_connections' => 0,
            'channels' => [],
        ];

        $uniqueUsers = [];

        foreach ($this->presenceChannels as $channel => $members) {
            $channelConnections = 0;
            foreach ($members as $userId => $data) {
                $uniqueUsers[$userId] = true;
                $channelConnections += count($data['connections']);
            }

            $stats['channels'][$channel] = [
                'member_count' => count($members),
                'connection_count' => $channelConnections,
            ];

            $stats['total_connections'] += $channelConnections;
        }

        $stats['total_unique_members'] = count($uniqueUsers);

        return $stats;
    }
}
