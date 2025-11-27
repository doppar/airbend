<?php

namespace Doppar\Airbend\Broadcasting\Drivers;

use Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent;
use Doppar\Airbend\Broadcasting\Contracts\BroadcastDriver;

class NullDriver implements BroadcastDriver
{
    /**
     * Broadcast an event to a channel
     *
     * @param string $channel
     * @param BroadcastEvent $event
     * @param array $options
     * @return void
     */
    public function broadcast(string $channel, BroadcastEvent $event, array $options = []): void
    {
        //
    }

    /**
     * Authenticate private/presence channel
     *
     * @param string $socketId
     * @param string $channel
     * @param array|null $userData
     * @return array
     */
    public function authenticate(string $socketId, string $channel, ?array $userData = null): array
    {
        return [];
    }
}