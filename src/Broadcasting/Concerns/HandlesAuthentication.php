<?php

namespace Doppar\Airbend\Broadcasting\Concerns;

use Ratchet\ConnectionInterface;
use Phaseolies\Support\Facades\Log;

trait HandlesAuthentication
{
    /**
     * Authenticate a private channel subscription
     *
     * @param ConnectionInterface $conn
     * @param string $channel
     * @param array $authData
     * @return bool
     */
    protected function authenticatePrivateChannel(ConnectionInterface $conn, string $channel,  array $authData): bool
    {
        $socketId = $this->clientMetadata[$conn->resourceId]['socket_id'] ?? null;
        $auth = $authData['auth'] ?? null;

        if (!$auth || !$socketId) {
            return false;
        }

        $signature = $this->generateAuthSignature($socketId, $channel);

        if (!hash_equals($signature, $auth)) {
            Log::warning("Invalid auth signature for channel: {$channel}");
            return false;
        }

        return true;
    }

    /**
     * Authenticate a presence channel subscription
     *
     * @param ConnectionInterface $conn
     * @param string $channel
     * @param array $authData
     * @return array|false Returns user data or false
     */
    protected function authenticatePresenceChannel(ConnectionInterface $conn, string $channel,  array $authData): array|false
    {
        $socketId = $this->clientMetadata[$conn->resourceId]['socket_id'] ?? null;
        $auth = $authData['auth'] ?? null;
        $channelData = $authData['channel_data'] ?? null;

        if (!$auth || !$socketId || !$channelData) {
            return false;
        }

        // Decode channel data
        $userData = json_decode($channelData, true);
        if (!$userData || !isset($userData['user_id'])) {
            return false;
        }

        // Verify HMAC signature including channel data
        $signature = $this->generatePresenceAuthSignature($socketId, $channel, $channelData);

        if (!hash_equals($signature, $auth)) {
            Log::warning("Invalid presence auth for channel: {$channel}");
            return false;
        }

        return $userData;
    }

    /**
     * Generate authentication signature for private channels
     *
     * @param string $socketId
     * @param string $channel
     * @return string
     */
    protected function generateAuthSignature(string $socketId, string $channel): string
    {
        $appKey = config('airbend.websocket.app_key');
        $appSecret = config('airbend.websocket.app_secret');
        $stringToSign = "{$socketId}:{$channel}";

        return "{$appKey}:" . hash_hmac('sha256', $stringToSign, $appSecret);
    }

    /**
     * Generate authentication signature for presence channels
     *
     * @param string $socketId
     * @param string $channel
     * @param string $channelData
     * @return string
     */
    protected function generatePresenceAuthSignature(string $socketId, string $channel,  string $channelData): string
    {
        $appKey = config('airbend.websocket.app_key');
        $appSecret = config('airbend.websocket.app_secret');
        $stringToSign = "{$socketId}:{$channel}:{$channelData}";

        return "{$appKey}:" . hash_hmac('sha256', $stringToSign, $appSecret);
    }

    /**
     * Subscribe to a private channel
     *
     * @param ConnectionInterface $conn
     * @param string $channel
     * @param array $authData
     * @return void
     */
    protected function subscribeToPrivateChannel(ConnectionInterface $conn, string $channel,  array $authData): void
    {
        if (!$this->authenticatePrivateChannel($conn, $channel, $authData)) {
            $this->sendError($conn, 'Authentication failed for private channel');
            return;
        }

        if (!isset($this->privateChannels[$channel])) {
            $this->privateChannels[$channel] = [];
        }

        $this->privateChannels[$channel][] = $conn;
        $this->channels[$channel][] = $conn;
        $this->clientMetadata[$conn->resourceId]['subscribed_channels'][] = $channel;

        $this->sendToClient($conn, [
            'event' => 'doppar:subscription_succeeded',
            'channel' => $channel,
        ]);

        Log::info("Client {$conn->resourceId} subscribed to private channel: {$channel}");
    }
}
