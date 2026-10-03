<?php

declare(strict_types=1);

namespace Doppar\Airbend\Tests\Unit;

use Doppar\Airbend\Broadcasting\WebSocketHandler;
use Doppar\Airbend\Tests\Support\BootsFramework;
use Mockery;
use PHPUnit\Framework\TestCase;
use Workerman\Connection\TcpConnection;

class WebSocketHandlerSecurityTest extends TestCase
{
    use BootsFramework;

    private WebSocketHandler $handler;

    /** @var array<int, array<int, array<string, mixed>>> frames received, per connection id */
    private array $frames = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFramework();
        $this->handler = new WebSocketHandler();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function connect(int $id): TcpConnection
    {
        $conn = Mockery::mock(TcpConnection::class);
        $conn->id = $id;
        $conn->shouldReceive('getRemoteAddress')->andReturn('127.0.0.1:1');
        $conn->shouldReceive('send')->andReturnUsing(function (string $payload) use ($id) {
            $this->frames[$id][] = json_decode($payload, true);

            return true;
        });
        $this->handler->onOpen($conn);

        return $conn;
    }

    private function socketId(int $id): string
    {
        return $this->handler->clientMetadata[$id]['socket_id'];
    }

    private function send(TcpConnection $conn, array|string $message): void
    {
        $this->handler->onMessage($conn, is_array($message) ? json_encode($message) : $message);
    }

    /** @return array<int, string> events received by a connection */
    private function events(int $id): array
    {
        return array_map(fn($f) => $f['event'] ?? '', $this->frames[$id] ?? []);
    }

    private function sign(int $id, string $channel, ?string $channelData = null): string
    {
        $string = $this->socketId($id) . ':' . $channel . ($channelData !== null ? ':' . $channelData : '');

        return 'test-key:' . hash_hmac('sha256', $string, 'unit-test-secret');
    }

    public function testMalformedMessagesNeverThrow(): void
    {
        $conn = $this->connect(1);

        $payloads = [
            'not json',
            '[]',
            '{"event":123}',
            '{"event":""}',
            '{"event":["x"]}',
            '{"event":"doppar:subscribe","channel":["a"]}',
            '{"event":"doppar:subscribe","channel":123}',
            '{"event":"doppar:subscribe","channel":"bad channel!"}',
            '{"event":"doppar:subscribe","channel":"private-x","data":{"auth":["array"]}}',
            '{"event":"doppar:subscribe","channel":"presence-x","data":{"auth":"a","channel_data":{"x":1}}}',
            '{"event":"client-event","channel":"private-x","data":"str"}',
            '{"event":"client-event"}',
            '{"event":"doppar:unsubscribe","channel":["a"]}',
            str_repeat('a', WebSocketHandler::MAX_MESSAGE_BYTES + 1),
        ];

        foreach ($payloads as $payload) {
            $this->send($conn, $payload);
        }

        $this->assertSame(count($payloads), count(array_filter($this->events(1), fn($e) => $e === 'doppar:error')));
    }

    public function testUnknownEventsAreRejectedInsteadOfBroadcast(): void
    {
        $victim = $this->connect(1);
        $attacker = $this->connect(2);
        $this->send($victim, ['event' => 'doppar:subscribe', 'channel' => 'news']);
        $this->frames = [];

        $this->send($attacker, ['event' => 'doppar:member_removed', 'channel' => 'news', 'data' => ['x' => 1]]);

        $this->assertSame([], $this->frames[1] ?? []);
        $this->assertSame(['doppar:error'], $this->events(2));
    }

    public function testPrivateChannelRejectsForgedSignature(): void
    {
        $conn = $this->connect(1);

        $this->send($conn, ['event' => 'doppar:subscribe', 'channel' => 'private-a', 'data' => ['auth' => 'test-key:deadbeef']]);

        $this->assertContains('doppar:error', $this->events(1));
        $this->assertFalse($this->handler->channelExists('private-a'));
    }

    public function testPrivateChannelAcceptsValidSignature(): void
    {
        $conn = $this->connect(1);

        $this->send($conn, ['event' => 'doppar:subscribe', 'channel' => 'private-a', 'data' => ['auth' => $this->sign(1, 'private-a')]]);

        $this->assertContains('doppar:subscription_succeeded', $this->events(1));
        $this->assertTrue($this->handler->channelExists('private-a'));
    }

    public function testChannelDataStringFromPusherStyleClientsIsAccepted(): void
    {
        $conn = $this->connect(1);

        $this->send($conn, ['event' => 'doppar:subscribe', 'channel' => 'private-a', 'data' => json_encode(['auth' => $this->sign(1, 'private-a')])]);

        $this->assertTrue($this->handler->channelExists('private-a'));
    }

    public function testRepeatedSubscribeDoesNotDuplicateDelivery(): void
    {
        $a = $this->connect(1);
        $b = $this->connect(2);

        for ($i = 0; $i < 3; $i++) {
            $this->send($a, ['event' => 'doppar:subscribe', 'channel' => 'news']);
        }
        $this->frames = [];

        $this->handler->broadcastToChannel('news', ['event' => 'x', 'channel' => 'news']);

        $this->assertSame(['x'], $this->events(1));
        $this->assertSame(1, $this->handler->getChannelStats()['channels']['news']['subscriber_count']);
    }

    public function testPresenceSubscribeIsIdempotentAndTracksMembers(): void
    {
        $a = $this->connect(1);
        $data = json_encode(['user_id' => 7, 'user_info' => ['name' => 'A']]);

        for ($i = 0; $i < 2; $i++) {
            $this->send($a, ['event' => 'doppar:subscribe', 'channel' => 'presence-r', 'data' => ['auth' => $this->sign(1, 'presence-r', $data), 'channel_data' => $data]]);
        }

        $this->assertSame(1, $this->handler->getPresenceCount('presence-r'));
        $this->assertSame(1, $this->handler->getPresenceMembersDetailed('presence-r')[0]['connections']);

        $this->handler->onClose($a);
        $this->assertSame(0, $this->handler->getPresenceCount('presence-r'));
    }

    public function testPresenceRejectsTamperedChannelData(): void
    {
        $a = $this->connect(1);
        $signed = json_encode(['user_id' => 7]);
        $tampered = json_encode(['user_id' => 1]);

        $this->send($a, ['event' => 'doppar:subscribe', 'channel' => 'presence-r', 'data' => ['auth' => $this->sign(1, 'presence-r', $signed), 'channel_data' => $tampered]]);

        $this->assertSame(0, $this->handler->getPresenceCount('presence-r'));
    }

    public function testClientEventRequiresSubscription(): void
    {
        $member = $this->connect(1);
        $outsider = $this->connect(2);
        $this->send($member, ['event' => 'doppar:subscribe', 'channel' => 'private-a', 'data' => ['auth' => $this->sign(1, 'private-a')]]);
        $this->frames = [];

        $this->send($outsider, ['event' => 'client-event', 'channel' => 'private-a', 'data' => ['event' => 'client-typing', 'data' => []]]);

        $this->assertSame([], $this->frames[1] ?? []);
        $this->assertSame(['doppar:error'], $this->events(2));
    }

    public function testClientEventCannotImpersonateServerEvents(): void
    {
        $a = $this->connect(1);
        $b = $this->connect(2);
        foreach ([1 => $a, 2 => $b] as $id => $conn) {
            $this->send($conn, ['event' => 'doppar:subscribe', 'channel' => 'private-a', 'data' => ['auth' => $this->sign($id, 'private-a')]]);
        }
        $this->frames = [];

        $this->send($a, ['event' => 'client-event', 'channel' => 'private-a', 'data' => ['event' => 'doppar:member_removed', 'data' => []]]);

        $this->assertSame([], $this->frames[2] ?? []);
    }

    public function testWhisperIsDeliveredToOthersOnly(): void
    {
        $a = $this->connect(1);
        $b = $this->connect(2);
        foreach ([1 => $a, 2 => $b] as $id => $conn) {
            $this->send($conn, ['event' => 'doppar:subscribe', 'channel' => 'private-a', 'data' => ['auth' => $this->sign($id, 'private-a')]]);
        }
        $this->frames = [];

        $this->send($a, ['event' => 'client-event', 'channel' => 'private-a', 'data' => ['event' => 'client-typing', 'data' => ['user' => 'a']]]);

        $this->assertSame([], $this->frames[1] ?? []);
        $this->assertSame('client-typing', $this->frames[2][0]['event']);
        $this->assertSame('private-a', $this->frames[2][0]['channel']);
        $this->assertSame(['user' => 'a'], json_decode($this->frames[2][0]['data'], true));
    }

    public function testClientEventOnPublicChannelIsRejected(): void
    {
        $a = $this->connect(1);
        $this->send($a, ['event' => 'doppar:subscribe', 'channel' => 'news']);
        $this->frames = [];

        $this->send($a, ['event' => 'client-event', 'channel' => 'news', 'data' => ['event' => 'client-x']]);

        $this->assertSame(['doppar:error'], $this->events(1));
    }

    public function testUnsubscribeRemovesDeliveryAndMetadata(): void
    {
        $a = $this->connect(1);
        $this->send($a, ['event' => 'doppar:subscribe', 'channel' => 'news']);
        $this->send($a, ['event' => 'doppar:unsubscribe', 'channel' => 'news']);
        $this->frames = [];

        $this->handler->broadcastToChannel('news', ['event' => 'x']);

        $this->assertSame([], $this->frames[1] ?? []);
        $this->assertSame([], $this->handler->clientMetadata[1]['subscribed_channels']);
    }

    public function testSubscriptionCapPerConnection(): void
    {
        $a = $this->connect(1);

        for ($i = 0; $i < WebSocketHandler::MAX_CHANNELS_PER_CONNECTION + 5; $i++) {
            $this->send($a, ['event' => 'doppar:subscribe', 'channel' => "c{$i}"]);
        }

        $this->assertCount(WebSocketHandler::MAX_CHANNELS_PER_CONNECTION, $this->handler->clientMetadata[1]['subscribed_channels']);
    }

    public function testPingStillWorks(): void
    {
        $a = $this->connect(1);
        $this->send($a, ['event' => 'doppar:ping']);

        $this->assertContains('doppar:pong', $this->events(1));
    }

    public function testStaleCleanupHonoursConfiguredTimeout(): void
    {
        $conn = $this->connect(1);
        $conn->shouldReceive('close')->once();
        $this->handler->clientMetadata[1]['last_heartbeat'] = time() - 181;

        $this->handler->cleanupStaleConnections();

        $this->addToAssertionCount(1);
    }
}
