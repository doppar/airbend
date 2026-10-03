<?php

declare(strict_types=1);

namespace Doppar\Airbend\Tests\Unit;

use Doppar\Airbend\Broadcasting\InternalMessageSigner;
use Doppar\Airbend\Tests\Support\BootsFramework;
use PHPUnit\Framework\TestCase;

class InternalMessageSignerTest extends TestCase
{
    use BootsFramework;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFramework();
    }

    public function testRoundTrip(): void
    {
        $payload = ['type' => 'broadcast', 'event' => 'e', 'channel' => 'c', 'timestamp' => time()];

        $this->assertSame($payload, InternalMessageSigner::verify(InternalMessageSigner::sign($payload)));
    }

    public function testRejectsTamperedPayload(): void
    {
        $envelope = json_decode(InternalMessageSigner::sign(['channel' => 'a', 'timestamp' => time()]), true);
        $envelope['payload'] = str_replace('"a"', '"b"', $envelope['payload']);

        $this->assertNull(InternalMessageSigner::verify(json_encode($envelope)));
    }

    public function testRejectsWrongSecret(): void
    {
        $signed = InternalMessageSigner::sign(['timestamp' => time()]);
        $this->bootFramework('another-secret');

        $this->assertNull(InternalMessageSigner::verify($signed));
    }

    public function testRejectsExpiredAndFutureMessages(): void
    {
        $this->assertNull(InternalMessageSigner::verify(InternalMessageSigner::sign(['timestamp' => time() - 301])));
        $this->assertNull(InternalMessageSigner::verify(InternalMessageSigner::sign(['timestamp' => time() + 301])));
    }

    public function testRejectsMessagesWithoutTimestamp(): void
    {
        $this->assertNull(InternalMessageSigner::verify(InternalMessageSigner::sign(['event' => 'x'])));
    }

    public function testRejectsGarbageAndUnsignedJson(): void
    {
        foreach (['', 'nope', '[]', '{"type":"broadcast","channel":"c","event":"e"}', '{"payload":1,"signature":2}'] as $raw) {
            $this->assertNull(InternalMessageSigner::verify($raw));
        }
    }
}
