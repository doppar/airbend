<?php

namespace Doppar\Airbend\Support\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Broadcast
{
    /**
     * The channels to broadcast on
     *
     * @var array|string
     */
    public array|string $channels;

    /**
     * Custom event name
     *
     * @var string|null
     */
    public ?string $as;

    /**
     * Broadcast to others only
     *
     * @var bool
     */
    public bool $toOthers;

    /**
     * Create a new broadcast attribute
     *
     * @param array|string $channels
     * @param string|null $as
     * @param bool $toOthers
     */
    public function __construct(array|string $channels, ?string $as = null, bool $toOthers = false)
    {
        $this->channels = $channels;
        $this->as = $as;
        $this->toOthers = $toOthers;
    }

    /**
     * Get the channels as an array
     *
     * @return array
     */
    public function getChannelsAsArray(): array
    {
        return is_array($this->channels) ? $this->channels : [$this->channels];
    }

    /**
     * Check if a specific channel is included
     *
     * @param string $channel
     * @return bool
     */
    public function hasChannel(string $channel): bool
    {
        $channels = $this->getChannelsAsArray();

        return in_array($channel, $channels);
    }
}