<?php

namespace Doppar\Airbend\Support\Facades;

use Phaseolies\Facade\BaseFacade;

/**
 * Provides a static interface to the broadcasting system
 *
 * @method static void channel(string|array $channels, \Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent $event)
 * @method static \Doppar\Airbend\Broadcasting\BroadcastManager toOthers()
 * @method static \Doppar\Airbend\Broadcasting\BroadcastManager driver(string|null $driver = null)
 * @method static \Doppar\Airbend\Broadcasting\BroadcastManager event(BroadcastEvent $event)
 * @method static mixed __callStatic(string $method, array $arguments)
 *
 * @see \Doppar\Airbend\Broadcasting\BroadcastManager
 */
class Broadcast extends BaseFacade
{
    /**
     * Get the registered name of the component
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'broadcast';
    }
}
