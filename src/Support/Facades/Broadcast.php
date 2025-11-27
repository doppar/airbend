<?php

namespace Doppar\Airbend\Support\Facades;

use Phaseolies\Facade\BaseFacade;

/**
 * Provides a static interface to the broadcasting system
 *
 * @method static channel(string|array $channels, \Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent $event)
 * @method static toOthers(\Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent $event)
 * @method static \Doppar\Airbend\Broadcasting\BroadcastManager driver(string|null $driver = null)
 * @method static connection(string|null $connection = null)
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
