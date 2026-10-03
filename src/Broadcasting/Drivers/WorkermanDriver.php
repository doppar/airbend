<?php

namespace Doppar\Airbend\Broadcasting\Drivers;

use Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastDriver;
use Doppar\Airbend\Broadcasting\InternalMessageSigner;
use Doppar\Airbend\Configuration\ConfigurationManager;
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
        $this->internalHost = ConfigurationManager::get('websocket.internal_host', '127.0.0.1');
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
            $this->socket = null;

            throw new \Exception(
                "Failed to connect to internal broadcast server at {$this->internalHost}:{$this->internalPort} - {$errstr} ({$errno})"
            );
        }

        stream_set_blocking($this->socket, true);

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

        $payload = [
            'type' => 'broadcast',
            'event' => $event->broadcastAs(),
            'channel' => $channel,
            'data' => $event->broadcastWith(),
            'socket_id' => $options['except'] ?? null,
            'timestamp' => time(),
            'message_id' => uniqid('msg_', true),
        ];

        // A failure is not swallowed: the broadcast manager logs it with the channel and event.
        $this->sendMessage($payload);
    }

    /**
     * Send a signed message through the socket connection
     *
     * The socket is blocking so a partial write is retried until the whole
     * frame is out, and the server's acknowledgement is read so errors surface.
     *
     * @param array $message
     * @return void
     * @throws \Exception
     */
    protected function sendMessage(array $message): void
    {
        $data = InternalMessageSigner::sign($message) . "\n";

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->connect();

                if ($this->writeAll($data)) {
                    $this->readAcknowledgement();

                    return;
                }
            } catch (\Exception $e) {
                if ($attempt === 1) {
                    throw $e;
                }
            }

            // A stale keep-alive connection: reconnect once and resend
            $this->disconnect();
        }

        throw new \Exception('Failed to send broadcast message to internal server');
    }

    /**
     * Write the whole buffer, handling partial writes
     *
     * @param string $data
     * @return bool
     */
    protected function writeAll(string $data): bool
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($this->socket, substr($data, $offset));

            if ($written === false || $written === 0) {
                return false;
            }

            $offset += $written;
        }

        return true;
    }

    /**
     * Read the server's reply so a rejected broadcast is not silently lost
     *
     * @return void
     * @throws \Exception
     */
    protected function readAcknowledgement(): void
    {
        if (!is_resource($this->socket)) {
            return;
        }

        $line = @fgets($this->socket);

        if ($line === false) {
            // No reply within the timeout (or the peer closed): treat the link as stale
            $this->disconnect();

            return;
        }

        $reply = json_decode($line, true);

        if (is_array($reply) && ($reply['type'] ?? null) === 'error') {
            throw new \Exception('Internal broadcast server rejected the message: ' . ($reply['message'] ?? 'unknown error'));
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
