<?php

declare(strict_types=1);

namespace Doppar\Airbend\Tests\Unit;

use Doppar\Airbend\Broadcasting\Contracts\BroadcastDriver;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent;
use PHPUnit\Framework\TestCase;
use Mockery;

class BroadcastContractsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testBroadcastDriverInterface()
    {
        $driver = Mockery::mock(BroadcastDriver::class);
        $event = Mockery::mock(BroadcastEvent::class);

        $driver->shouldReceive('broadcast')
            ->once()
            ->with('test-channel', $event, []);

        $driver->shouldReceive('authenticate')
            ->once()
            ->with('socket-123', 'private-channel', null)
            ->andReturn(['auth' => 'signature']);

        $driver->broadcast('test-channel', $event, []);
        $result = $driver->authenticate('socket-123', 'private-channel', null);

        $this->assertIsArray($result);
    }

    public function testBroadcastEventInterface()
    {
        $event = Mockery::mock(BroadcastEvent::class);

        $event->shouldReceive('broadcastOn')->once()->andReturn(['channel-1', 'channel-2']);
        $event->shouldReceive('broadcastAs')->once()->andReturn('TestEvent');
        $event->shouldReceive('broadcastWith')->once()->andReturn(['key' => 'value']);
        $event->shouldReceive('shouldBroadcast')->once()->andReturn(true);
        $event->shouldReceive('isBroadcastingToOthers')->once()->andReturn(false);

        $this->assertEquals(['channel-1', 'channel-2'], $event->broadcastOn());
        $this->assertEquals('TestEvent', $event->broadcastAs());
        $this->assertEquals(['key' => 'value'], $event->broadcastWith());
        $this->assertTrue($event->shouldBroadcast());
        $this->assertFalse($event->isBroadcastingToOthers());
    }

    public function testBroadcastEventCanReturnStringChannel()
    {
        $event = Mockery::mock(BroadcastEvent::class);
        $event->shouldReceive('broadcastOn')->once()->andReturn('single-channel');

        $channels = $event->broadcastOn();
        $this->assertIsString($channels);
        $this->assertEquals('single-channel', $channels);
    }

    public function testBroadcastEventCanReturnArrayChannels()
    {
        $event = Mockery::mock(BroadcastEvent::class);
        $event->shouldReceive('broadcastOn')->once()->andReturn(['channel-1', 'channel-2']);

        $channels = $event->broadcastOn();
        $this->assertIsArray($channels);
        $this->assertCount(2, $channels);
    }

    public function testBroadcastDriverAuthenticateWithUserData()
    {
        $driver = Mockery::mock(BroadcastDriver::class);
        $userData = ['user_id' => 123, 'user_info' => ['name' => 'John']];

        $driver->shouldReceive('authenticate')
            ->once()
            ->with('socket-123', 'presence-channel', $userData)
            ->andReturn(['auth' => 'signature', 'channel_data' => json_encode($userData)]);

        $result = $driver->authenticate('socket-123', 'presence-channel', $userData);

        $this->assertArrayHasKey('auth', $result);
        $this->assertArrayHasKey('channel_data', $result);
    }

    public function testBroadcastDriverAuthenticatePrivateChannel()
    {
        $driver = Mockery::mock(BroadcastDriver::class);

        $driver->shouldReceive('authenticate')
            ->once()
            ->with('socket-123', 'private-channel', null)
            ->andReturn(['auth' => 'app-key:signature']);

        $result = $driver->authenticate('socket-123', 'private-channel', null);

        $this->assertArrayHasKey('auth', $result);
        $this->assertArrayNotHasKey('channel_data', $result);
    }

    public function testBroadcastDriverBroadcastWithOptions()
    {
        $driver = Mockery::mock(BroadcastDriver::class);
        $event = Mockery::mock(BroadcastEvent::class);
        $options = ['except' => 'socket-123', 'to_others' => true];

        $driver->shouldReceive('broadcast')
            ->once()
            ->with('test-channel', $event, $options);

        $driver->broadcast('test-channel', $event, $options);

        $this->assertTrue(true);
    }

    public function testBroadcastEventEmptyData()
    {
        $event = Mockery::mock(BroadcastEvent::class);
        $event->shouldReceive('broadcastWith')->once()->andReturn([]);

        $data = $event->broadcastWith();
        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    public function testBroadcastEventComplexData()
    {
        $event = Mockery::mock(BroadcastEvent::class);
        $complexData = [
            'user' => ['id' => 1, 'name' => 'John'],
            'items' => ['item1', 'item2'],
            'metadata' => ['timestamp' => time()],
        ];

        $event->shouldReceive('broadcastWith')->once()->andReturn($complexData);

        $data = $event->broadcastWith();
        $this->assertArrayHasKey('user', $data);
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('metadata', $data);
    }
}
