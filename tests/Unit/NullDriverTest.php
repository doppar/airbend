<?php

declare(strict_types=1);

namespace Doppar\Airbend\Tests\Unit;

use Doppar\Airbend\Broadcasting\Drivers\NullDriver;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent;
use PHPUnit\Framework\TestCase;
use Mockery;

class NullDriverTest extends TestCase
{
    private NullDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new NullDriver();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testBroadcastDoesNothing()
    {
        $event = Mockery::mock(BroadcastEvent::class);
        $event->shouldReceive('shouldBroadcast')->andReturn(true);
        $event->shouldReceive('broadcastAs')->never();
        $event->shouldReceive('broadcastWith')->never();

        // Should not throw any exceptions
        $this->driver->broadcast('test-channel', $event);

        $this->assertTrue(true); // Assert test ran successfully
    }

    public function testBroadcastWithOptions()
    {
        $event = Mockery::mock(BroadcastEvent::class);
        $options = ['except' => 'socket-123', 'to_others' => true];

        $this->driver->broadcast('test-channel', $event, $options);

        $this->assertTrue(true);
    }

    public function testAuthenticateReturnsEmptyArray()
    {
        $result = $this->driver->authenticate('socket-123', 'private-channel');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testAuthenticateWithUserData()
    {
        $userData = ['user_id' => 123, 'user_info' => ['name' => 'John']];
        $result = $this->driver->authenticate('socket-123', 'presence-channel', $userData);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testAuthenticateWithNullUserData()
    {
        $result = $this->driver->authenticate('socket-123', 'private-channel', null);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testBroadcastToMultipleChannels()
    {
        $event = Mockery::mock(BroadcastEvent::class);

        $this->driver->broadcast('channel-1', $event);
        $this->driver->broadcast('channel-2', $event);
        $this->driver->broadcast('channel-3', $event);

        $this->assertTrue(true);
    }
}
