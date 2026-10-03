<?php

declare(strict_types=1);

namespace Doppar\Airbend\Tests\Unit;

use Doppar\Airbend\Broadcasting\Channel;
use Doppar\Airbend\Broadcasting\Events\BaseBroadcastEvent;
use Doppar\Airbend\Configuration\ConfigurationManager;
use Doppar\Airbend\Exceptions\BroadcastConfigurationException;
use Doppar\Airbend\Support\Attributes\Broadcast;
use Doppar\Airbend\Tests\Support\BootsFramework;
use PHPUnit\Framework\TestCase;

class ConfigurationSecretsTest extends TestCase
{
    use BootsFramework;

    public function testEmptySecretIsAlwaysRejected(): void
    {
        $this->bootFramework('', 'local');

        $this->expectException(BroadcastConfigurationException::class);
        ConfigurationManager::appSecret();
    }

    public function testPlaceholderSecretRejectedInProduction(): void
    {
        $this->bootFramework('doppar-app-secret', 'production');

        $this->expectException(BroadcastConfigurationException::class);
        ConfigurationManager::appSecret();
    }

    public function testPlaceholderSecretToleratedInLocal(): void
    {
        $this->bootFramework('doppar-app-secret', 'local');

        $this->assertSame('doppar-app-secret', ConfigurationManager::appSecret());
    }

    public function testRealSecretAcceptedInProduction(): void
    {
        $this->bootFramework('a-long-unique-secret', 'production');

        $this->assertSame('a-long-unique-secret', ConfigurationManager::appSecret());
        $this->assertSame('test-key', ConfigurationManager::appKey());
    }

    public function testChannelPatternsTreatLiteralsLiterally(): void
    {
        Channel::clearAuthorizers();
        Channel::authorize('private-chat.{id}', fn($r, $id) => $id === '1');

        $this->assertNotNull(Channel::getAuthorizer('private-chat.1'));
        $this->assertNull(Channel::getAuthorizer('private-chatX1'));
        $this->assertTrue(Channel::authorizeChannel(new \stdClass(), 'private-chat.1'));
        $this->assertFalse(Channel::authorizeChannel(new \stdClass(), 'private-chat.2'));
        Channel::clearAuthorizers();
    }

    public function testChannelCallbackResultIsNormalised(): void
    {
        Channel::clearAuthorizers();
        Channel::authorize('private-a', fn() => null);

        $this->assertFalse(Channel::authorizeChannel(new \stdClass(), 'private-a'));
        Channel::clearAuthorizers();
    }

    public function testAttributeToOthersIsHonoured(): void
    {
        $event = new #[Broadcast('c', toOthers: true)] class extends BaseBroadcastEvent {};
        $plain = new #[Broadcast('c')] class extends BaseBroadcastEvent {};

        $this->assertTrue($event->isBroadcastingToOthers());
        $this->assertFalse($plain->isBroadcastingToOthers());
    }
}
