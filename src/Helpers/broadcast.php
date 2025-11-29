<?php

use Doppar\Airbend\Support\Facades\Broadcast;

if (!function_exists('broadcast')) {
    /**
     * Broadcast an event to one or many channels.
     *
     * @param mixed $event
     * @return void
     */
    function broadcast($event): void
    {
        Broadcast::event($event);
    }
}
