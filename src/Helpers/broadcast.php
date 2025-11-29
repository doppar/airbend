<?php

use Doppar\Airbend\Support\Facades\Broadcast;

if (!function_exists('broadcast')) {
    /**
     * Broadcast an event to one or many channels.
     *
     * @param string|array $channels
     * @param mixed $event
     * @return void
     */
    function broadcast(string|array $channels, $event): void
    {
        Broadcast::channel($channels, $event);
    }
}

if (!function_exists('broadcast_to_others')) {
    /**
     * Broadcast an event to all subscribers except the current socket.
     *
     * @param string|array $channels
     * @param mixed $event
     * @return void
     */
    function broadcast_to_others(string|array $channels, $event): void
    {
        Broadcast::toOthers()->channel($channels, $event);
    }
}
