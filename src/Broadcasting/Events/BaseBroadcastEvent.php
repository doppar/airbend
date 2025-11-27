<?php

namespace Doppar\Airbend\Broadcasting\Events;

use Doppar\Airbend\Broadcasting\Contracts\BroadcastEvent;
use Doppar\Airbend\Support\Attributes\Broadcast as BroadcastAttribute;

abstract class BaseBroadcastEvent implements BroadcastEvent
{
    /**
     * Broadcast channels
     *
     * @var array|string
     */
    protected array|string $channels = [];

    /**
     * Event name
     *
     * @var string|null
     */
    protected ?string $eventName = null;

    /**
     * Should this event broadcast
     *
     * @var bool
     */
    protected bool $broadcast = true;

    /**
     * Broadcast only to others
     *
     * @var bool
     */
    protected bool $toOthers = false;

    /**
     * Socket ID to exclude from broadcast
     *
     * @var string|null
     */
    protected ?string $exceptSocketId = null;

    /**
     * Get the channels the event should broadcast on
     *
     * @return array|string
     */
    public function broadcastOn(): array|string
    {
        $reflection = new \ReflectionClass($this);
        $attributes = $reflection->getAttributes(BroadcastAttribute::class);

        if (!empty($attributes)) {
            $attribute = $attributes[0]->newInstance();
            return $attribute->channels;
        }

        return $this->channels;
    }

    /**
     * Get the event name for broadcasting
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        $reflection = new \ReflectionClass($this);
        $attributes = $reflection->getAttributes(BroadcastAttribute::class);

        if (!empty($attributes)) {
            $attribute = $attributes[0]->newInstance();
            if ($attribute->as) {
                return $attribute->as;
            }
        }

        if ($this->eventName) {
            return $this->eventName;
        }

        $className = get_class($this);

        return substr($className, strrpos($className, '\\') + 1);
    }

    /**
     * Get the data to broadcast
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        $data = [];
        foreach ($properties as $property) {
            $name = $property->getName();
            // Skip internal properties
            if (!in_array($name, ['channels', 'eventName', 'broadcast', 'toOthers', 'exceptSocketId'])) {
                $data[$name] = $this->$name;
            }
        }

        return $data;
    }

    /**
     * Determine if this event should broadcast
     *
     * @return bool
     */
    public function shouldBroadcast(): bool
    {
        return $this->broadcast;
    }

    /**
     * Broadcast to others only
     *
     * @return self
     */
    public function toOthers(): self
    {
        $this->toOthers = true;

        return $this;
    }

    /**
     * Check if broadcasting to others
     *
     * @return bool
     */
    public function isBroadcastingToOthers(): bool
    {
        return $this->toOthers;
    }

    /**
     * Set socket ID to exclude
     *
     * @param string $socketId
     * @return self
     */
    public function except(string $socketId): self
    {
        $this->exceptSocketId = $socketId;

        return $this;
    }

    /**
     * Get excluded socket ID
     *
     * @return string|null
     */
    public function getExceptSocketId(): ?string
    {
        return $this->exceptSocketId;
    }

    /**
     * Set whether event should broadcast
     *
     * @param bool $broadcast
     * @return self
     */
    public function setShouldBroadcast(bool $broadcast): self
    {
        $this->broadcast = $broadcast;

        return $this;
    }

    /**
     * Set custom channels
     *
     * @param array|string $channels
     * @return self
     */
    public function setChannels(array|string $channels): self
    {
        $this->channels = $channels;

        return $this;
    }

    /**
     * Set custom event name
     *
     * @param string $eventName
     * @return self
     */
    public function setEventName(string $eventName): self
    {
        $this->eventName = $eventName;

        return $this;
    }
}