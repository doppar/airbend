<?php

namespace Doppar\Airbend\Broadcasting\Drivers;

use Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastDriver;
use Doppar\Airbend\Configuration\ConfigurationManager;
use Doppar\Airbend\Monitoring\MetricsCollector;
use Doppar\Airbend\Exceptions\BroadcastConfigurationException;

class WorkermanDriver implements BroadcastDriver
{
    /**
     * Socket connection
     *
     * @var resource|null
     */
    protected $socket = null;

    /**
     * Internal communication host
     *
     * @var string
     */
    protected string $internalHost;

    /**
     * Internal communication port
     *
     * @var int
     */
    protected int $internalPort;

    /**
     * Connection timeout in seconds
     *
     * @var int
     */
    protected int $connectionTimeout = 2;

    /**
     * Create a new Workerman driver
     * 
     * @throws BroadcastConfigurationException
     */
    public function __construct()
    {
        $this->internalHost = ConfigurationManager::get('websocket.host', '127.0.0.1');
        $this->internalPort = ConfigurationManager::get('websocket.internal_port', 6002);
    }

    /**
     * Establish connection to internal broadcast channel
     *
     * @return void
     * @throws \Exception
     */
    protected function connect(): void
    {
        if ($this->socket && !feof($this->socket)) {
            return;
        }

        $this->disconnect();

        $errno = 0;
        $errstr = '';

        $this->socket = @fsockopen(
            $this->internalHost,
            $this->internalPort,
            $errno,
            $errstr,
            $this->connectionTimeout
        );

        if ($this->socket === false) {
            throw new \Exception(
                "Failed to connect to internal broadcast server at {$this->internalHost}:{$this->internalPort} - {$errstr} ({$errno})"
            );
        }

        stream_set_blocking($this->socket, false);

        stream_set_timeout($this->socket, $this->connectionTimeout);
    }

    /**
     * Broadcast an event to a channel
     *
     * @param string $channel
     * @param BroadcastEvent $event
     * @param array<string, mixed> $options
     * @return void
     */
    public function broadcast(string $channel, BroadcastEvent $event, array $options = []): void
    {
        if (!$event->shouldBroadcast()) {
            return;
        }

        MetricsCollector::startTiming();

        try {
            $eventName = $event->broadcastAs();
            $eventData = $event->broadcastWith();

            $payload = [
                'type' => 'broadcast',
                'event' => $eventName,
                'channel' => $channel,
                'data' => $eventData,
                'socket_id' => $options['except'] ?? null,
                'timestamp' => time(),
                'message_id' => uniqid('msg_', true),
            ];

            $this->sendMessage($payload);

            MetricsCollector::recordMessage('sent');
        } catch (\Exception $e) {
            MetricsCollector::recordError('broadcast');
        } finally {
            MetricsCollector::endTiming('workerman_broadcast');
        }
    }

    /**
     * Send message through socket connection
     *
     * @param array $message
     * @return void
     * @throws \Exception
     */
    protected function sendMessage(array $message): void
    {
        $jsonMessage = json_encode($message, JSON_THROW_ON_ERROR);
        $data = $jsonMessage . "\n";

        if (!$this->socket || feof($this->socket)) {
            $this->connect();
        }

        $written = @fwrite($this->socket, $data);

        if ($written === false || $written === 0) {
            $this->disconnect();
            $this->connect();

            $written = @fwrite($this->socket, $data);

            if ($written === false || $written === 0) {
                throw new \Exception("Failed to send broadcast message to internal server");
            }
        }

        @fflush($this->socket);
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
        MetricsCollector::startTiming();

        try {
            $appKey = ConfigurationManager::get('authorize.app_key', 'doppar-app-key');
            $appSecret = ConfigurationManager::get('authorize.app_secret', 'doppar-app-secret');

            if (str_starts_with($channel, 'presence-')) {
                $channelData = json_encode($userData, JSON_THROW_ON_ERROR);
                $stringToSign = "{$socketId}:{$channel}:{$channelData}";
                $signature = hash_hmac('sha256', $stringToSign, $appSecret);

                return [
                    'auth' => "{$appKey}:{$signature}",
                    'channel_data' => $channelData,
                ];
            }

            $stringToSign = "{$socketId}:{$channel}";
            $signature = hash_hmac('sha256', $stringToSign, $appSecret);

            return [
                'auth' => "{$appKey}:{$signature}",
            ];
        } catch (\JsonException $e) {
            MetricsCollector::recordError('authentication');
            throw $e;
        } finally {
            MetricsCollector::endTiming('authentication');
        }
    }

    /**
     * Close the connection
     *
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Check if connection is active
     *
     * @return bool
     */
    protected function isConnected(): bool
    {
        return $this->socket !== null && !feof($this->socket);
    }

    /**
     * Get connection status
     *
     * @return array
     */
    public function getStatus(): array
    {
        return [
            'connected' => $this->isConnected(),
            'internal_host' => $this->internalHost,
            'internal_port' => $this->internalPort,
            'connection_timeout' => $this->connectionTimeout,
        ];
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
