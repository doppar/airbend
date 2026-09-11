<?php

declare(strict_types=1);

namespace Doppar\Airbend\Tests\Unit;

use Doppar\Airbend\Broadcasting\WebSocketHandler;
use Workerman\Connection\TcpConnection;
use PHPUnit\Framework\TestCase;
use Mockery;
use ReflectionClass;

class HandlesChannelsTest extends TestCase
{
    private WebSocketHandler $handler;
    private array $connections = [];
    private ReflectionClass $handlerReflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new WebSocketHandler();
        $this->handlerReflection = new ReflectionClass($this->handler);

        for ($i = 1; $i <= 3; $i++) {
            $conn = Mockery::mock(TcpConnection::class);
            $conn->id = $i;
            $this->connections[$i] = $conn;

            $clientMetadataProperty = $this->handlerReflection->getProperty('clientMetadata');
            $clientMetadata = $clientMetadataProperty->getValue($this->handler);
            $clientMetadata[$i] = [
                'socket_id' => "socket-{$i}",
                'subscribed_channels' => [],
                'last_heartbeat' => time(),
            ];
            $clientMetadataProperty->setValue($this->handler, $clientMetadata);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testGetActiveChannelsEmpty()
    {
        $channels = $this->handler->getActiveChannels();
        $this->assertIsArray($channels);
        $this->assertEmpty($channels);
    }

    public function testGetActiveChannelsWithSubscriptions()
    {
        $this->subscribeToChannel($this->connections[1], 'channel-1');
        $this->subscribeToChannel($this->connections[2], 'channel-2');
        $this->subscribeToChannel($this->connections[3], 'channel-1');

        $channels = $this->handler->getActiveChannels();

        $this->assertCount(2, $channels);
        $this->assertContains('channel-1', $channels);
        $this->assertContains('channel-2', $channels);
    }

    public function testGetChannelSubscriberCountEmpty()
    {
        $count = $this->handler->getChannelSubscriberCount('nonexistent');
        $this->assertEquals(0, $count);
    }

    public function testGetChannelSubscriberCountSingleSubscriber()
    {
        $this->subscribeToChannel($this->connections[1], 'test-channel');

        $count = $this->handler->getChannelSubscriberCount('test-channel');
        $this->assertEquals(1, $count);
    }

    public function testGetChannelSubscriberCountMultipleSubscribers()
    {
        $this->subscribeToChannel($this->connections[1], 'test-channel');
        $this->subscribeToChannel($this->connections[2], 'test-channel');
        $this->subscribeToChannel($this->connections[3], 'test-channel');

        $count = $this->handler->getChannelSubscriberCount('test-channel');
        $this->assertEquals(3, $count);
    }

    public function testChannelExistsReturnsFalseForNonexistent()
    {
        $exists = $this->handler->channelExists('nonexistent');
        $this->assertFalse($exists);
    }

    public function testChannelExistsReturnsTrueForExisting()
    {
        $this->subscribeToChannel($this->connections[1], 'test-channel');

        $exists = $this->handler->channelExists('test-channel');
        $this->assertTrue($exists);
    }

    public function testChannelExistsReturnsFalseAfterLastUnsubscribe()
    {
        $this->subscribeToChannel($this->connections[1], 'test-channel');
        $this->unsubscribeFromChannel($this->connections[1], 'test-channel');

        $exists = $this->handler->channelExists('test-channel');
        $this->assertFalse($exists);
    }

    public function testIsSubscribedReturnsFalseWhenNotSubscribed()
    {
        $isSubscribed = $this->handler->isSubscribed($this->connections[1], 'test-channel');
        $this->assertFalse($isSubscribed);
    }

    public function testIsSubscribedReturnsTrueWhenSubscribed()
    {
        $this->subscribeToChannel($this->connections[1], 'test-channel');

        $isSubscribed = $this->handler->isSubscribed($this->connections[1], 'test-channel');
        $this->assertTrue($isSubscribed);
    }

    public function testIsSubscribedReturnsFalseAfterUnsubscribe()
    {
        $this->subscribeToChannel($this->connections[1], 'test-channel');
        $this->unsubscribeFromChannel($this->connections[1], 'test-channel');

        $isSubscribed = $this->handler->isSubscribed($this->connections[1], 'test-channel');
        $this->assertFalse($isSubscribed);
    }

    public function testGetSubscribedChannelsEmpty()
    {
        $channels = $this->handler->getSubscribedChannels($this->connections[1]);
        $this->assertIsArray($channels);
        $this->assertEmpty($channels);
    }

    public function testGetSubscribedChannelsSingle()
    {
        $this->subscribeToChannel($this->connections[1], 'channel-1');

        $channels = $this->handler->getSubscribedChannels($this->connections[1]);

        $this->assertCount(1, $channels);
        $this->assertContains('channel-1', $channels);
    }

    public function testGetSubscribedChannelsMultiple()
    {
        $this->subscribeToChannel($this->connections[1], 'channel-1');
        $this->subscribeToChannel($this->connections[1], 'channel-2');
        $this->subscribeToChannel($this->connections[1], 'channel-3');

        $channels = $this->handler->getSubscribedChannels($this->connections[1]);

        $this->assertCount(3, $channels);
        $this->assertContains('channel-1', $channels);
        $this->assertContains('channel-2', $channels);
        $this->assertContains('channel-3', $channels);
    }

    public function testBroadcastToChannels()
    {
        $this->subscribeToChannel($this->connections[1], 'channel-1');
        $this->subscribeToChannel($this->connections[2], 'channel-2');
        $this->subscribeToChannel($this->connections[3], 'channel-1');

        $message = ['event' => 'test', 'data' => 'hello'];

        $this->connections[1]->shouldReceive('send')->once();
        $this->connections[2]->shouldReceive('send')->once();
        $this->connections[3]->shouldReceive('send')->once();

        $this->handler->broadcastToChannels(['channel-1', 'channel-2'], $message);
        $this->assertTrue(true);
    }

    public function testBroadcastToChannelsWithException()
    {
        $this->subscribeToChannel($this->connections[1], 'channel-1');
        $this->subscribeToChannel($this->connections[2], 'channel-2');

        $message = ['event' => 'test', 'data' => 'hello'];

        $this->connections[1]->shouldReceive('send')->once();
        $this->connections[2]->shouldReceive('send')->never();

        $this->handler->broadcastToChannels(['channel-1', 'channel-2'], $message, 2);
        $this->assertTrue(true);
    }

    public function testGetChannelStatsEmpty()
    {
        $clientsProperty = $this->handlerReflection->getProperty('clients');
        $clients = $clientsProperty->getValue($this->handler);
        $clients->offsetSet($this->connections[1]);

        $stats = $this->handler->getChannelStats();

        $this->assertEquals(0, $stats['total_channels']);
        $this->assertEquals(1, $stats['total_connections']);
        $this->assertEmpty($stats['channels']);
    }

    public function testGetChannelStatsWithPublicChannels()
    {
        $clientsProperty = $this->handlerReflection->getProperty('clients');
        $clients = $clientsProperty->getValue($this->handler);
        $clients->offsetSet($this->connections[1]);
        $clients->offsetSet($this->connections[2]);

        $this->subscribeToChannel($this->connections[1], 'public-channel');
        $this->subscribeToChannel($this->connections[2], 'public-channel');

        $stats = $this->handler->getChannelStats();

        $this->assertEquals(1, $stats['total_channels']);
        $this->assertEquals(2, $stats['total_connections']);
        $this->assertArrayHasKey('public-channel', $stats['channels']);
        $this->assertEquals('public', $stats['channels']['public-channel']['type']);
        $this->assertEquals(2, $stats['channels']['public-channel']['subscriber_count']);
    }

    public function testGetChannelStatsWithPrivateChannels()
    {
        $clientsProperty = $this->handlerReflection->getProperty('clients');
        $clients = $clientsProperty->getValue($this->handler);
        $clients->offsetSet($this->connections[1]);

        $this->subscribeToChannel($this->connections[1], 'private-channel');

        $stats = $this->handler->getChannelStats();

        $this->assertArrayHasKey('private-channel', $stats['channels']);
        $this->assertEquals('private', $stats['channels']['private-channel']['type']);
    }

    public function testGetChannelStatsWithPresenceChannels()
    {
        $clientsProperty = $this->handlerReflection->getProperty('clients');
        $clients = $clientsProperty->getValue($this->handler);
        $clients->offsetSet($this->connections[1]);

        $this->subscribeToChannel($this->connections[1], 'presence-channel');

        $presenceChannelsProperty = $this->handlerReflection->getProperty('presenceChannels');
        $presenceChannels = $presenceChannelsProperty->getValue($this->handler);
        $presenceChannels['presence-channel'] = [
            'user-1' => ['id' => 'user-1', 'info' => [], 'connections' => [1]],
        ];
        $presenceChannelsProperty->setValue($this->handler, $presenceChannels);

        $stats = $this->handler->getChannelStats();

        $this->assertArrayHasKey('presence-channel', $stats['channels']);
        $this->assertEquals('presence', $stats['channels']['presence-channel']['type']);
        $this->assertArrayHasKey('member_count', $stats['channels']['presence-channel']);
        $this->assertEquals(1, $stats['channels']['presence-channel']['member_count']);
    }

    public function testGetChannelStatsWithMixedChannels()
    {
        $clientsProperty = $this->handlerReflection->getProperty('clients');
        $clients = $clientsProperty->getValue($this->handler);
        $clients->offsetSet($this->connections[1]);
        $clients->offsetSet($this->connections[2]);

        $this->subscribeToChannel($this->connections[1], 'public-channel');
        $this->subscribeToChannel($this->connections[2], 'private-channel');

        $stats = $this->handler->getChannelStats();

        $this->assertEquals(2, $stats['total_channels']);
        $this->assertArrayHasKey('public-channel', $stats['channels']);
        $this->assertArrayHasKey('private-channel', $stats['channels']);
    }

    public function testTerminateChannelRemovesAllSubscribers()
    {
        $this->subscribeToChannel($this->connections[1], 'test-channel');
        $this->subscribeToChannel($this->connections[2], 'test-channel');

        $this->connections[1]->shouldReceive('send')->once();
        $this->connections[2]->shouldReceive('send')->once();

        $this->handler->terminateChannel('test-channel', 'Channel closed');

        $this->assertFalse($this->handler->channelExists('test-channel'));
    }

    public function testTerminateChannelWithoutReason()
    {
        $this->subscribeToChannel($this->connections[1], 'test-channel');

        $this->connections[1]->shouldReceive('send')->never();

        $this->handler->terminateChannel('test-channel');

        $this->assertFalse($this->handler->channelExists('test-channel'));
    }

    public function testTerminateChannelRemovesFromPresenceChannels()
    {
        $channel = 'presence-test';
        $this->subscribeToChannel($this->connections[1], $channel);

        $presenceChannelsProperty = $this->handlerReflection->getProperty('presenceChannels');
        $presenceChannels = $presenceChannelsProperty->getValue($this->handler);
        $presenceChannels[$channel] = [
            'user-1' => ['id' => 'user-1', 'info' => [], 'connections' => [1]],
        ];
        $presenceChannelsProperty->setValue($this->handler, $presenceChannels);

        $this->handler->terminateChannel($channel);

        $presenceChannels = $presenceChannelsProperty->getValue($this->handler);
        $this->assertArrayNotHasKey($channel, $presenceChannels);
    }

    public function testTerminateChannelRemovesFromPrivateChannels()
    {
        $channel = 'private-test';
        $this->subscribeToChannel($this->connections[1], $channel);

        $privateChannelsProperty = $this->handlerReflection->getProperty('privateChannels');
        $privateChannels = $privateChannelsProperty->getValue($this->handler);
        $privateChannels[$channel] = [$this->connections[1]];
        $privateChannelsProperty->setValue($this->handler, $privateChannels);

        $this->handler->terminateChannel($channel);

        $privateChannels = $privateChannelsProperty->getValue($this->handler);
        $this->assertArrayNotHasKey($channel, $privateChannels);
    }

    public function testTerminateNonexistentChannel()
    {
        $this->handler->terminateChannel('nonexistent');
        $this->assertFalse($this->handler->channelExists('nonexistent'));
    }

    private function subscribeToChannel(TcpConnection $conn, string $channel): void
    {
        $channelsProperty = $this->handlerReflection->getProperty('channels');
        $channels = $channelsProperty->getValue($this->handler);

        if (!isset($channels[$channel])) {
            $channels[$channel] = [];
        }
        $channels[$channel][] = $conn;
        $channelsProperty->setValue($this->handler, $channels);

        $clientMetadataProperty = $this->handlerReflection->getProperty('clientMetadata');
        $clientMetadata = $clientMetadataProperty->getValue($this->handler);

        $clientMetadata[$conn->id]['subscribed_channels'][] = $channel;
        $clientMetadataProperty->setValue($this->handler, $clientMetadata);
    }

    private function unsubscribeFromChannel(TcpConnection $conn, string $channel): void
    {
        $channelsProperty = $this->handlerReflection->getProperty('channels');
        $channels = $channelsProperty->getValue($this->handler);

        if (isset($channels[$channel])) {
            $channels[$channel] = array_filter(
                $channels[$channel],
                fn($c) => $c->id !== $conn->id
            );

            if (empty($channels[$channel])) {
                unset($channels[$channel]);
            }
            $channelsProperty->setValue($this->handler, $channels);
        }

        $clientMetadataProperty = $this->handlerReflection->getProperty('clientMetadata');
        $clientMetadata = $clientMetadataProperty->getValue($this->handler);

        if (isset($clientMetadata[$conn->id])) {
            $clientMetadata[$conn->id]['subscribed_channels'] = array_filter(
                $clientMetadata[$conn->id]['subscribed_channels'],
                fn($ch) => $ch !== $channel
            );
            $clientMetadataProperty->setValue($this->handler, $clientMetadata);
        }
    }
}
