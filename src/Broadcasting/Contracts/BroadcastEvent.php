<?php

namespace Doppar\Airbend\Broadcasting\Contracts;

interface BroadcastEvent
{
    /**
     * Get the channels the event should broadcast on
     *
     * @return array|string
     */
    public function broadcastOn(): array|string;

    /**
     * Get the event name for broadcasting
     *
     * @return string
     */
    public function broadcastAs(): string;

    /**
     * Get the data to broadcast
     *
     * @return array
     */
    public function broadcastWith(): array;

    /**
     * Determine if this event should broadcast
     *
     * @return bool
     */
    public function shouldBroadcast(): bool;

    /**
     * Check if broadcasting to others
     *
     * @return bool
     */
    public function isBroadcastingToOthers(): bool;
}
