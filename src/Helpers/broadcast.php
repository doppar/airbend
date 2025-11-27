<?php

use Doppar\Airbend\Support\Facades\Broadcast;

if (!function_exists('broadcast')) {
    function broadcast(string|array $channels, $event): void
    {
        Broadcast::channel($channels, $event);
    }
}

if (!function_exists('broadcast_to_others')) {
    function broadcast_to_others(string|array $channels, $event): void
    {
        Broadcast::toOthers()->channel($channels, $event);
    }
}
