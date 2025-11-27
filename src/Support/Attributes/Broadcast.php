<?php

namespace Doppar\Airbend\Support\Attributes;

use Attribute;

/**
 * Broadcast Attribute
 *
 * Defines broadcasting configuration for event classes using PHP 8 attributes.
 * This attribute allows you to declaratively specify broadcast channels,
 * event names, and broadcasting behavior directly on event classes.
 *
 * @example Basic usage with single channel:
 * ```php
 * #[Broadcast(channels: 'notifications')]
 * class NotificationSent extends BaseBroadcastEvent {}
 * ```
 *
 * @example With custom event name:
 * ```php
 * #[Broadcast(channels: 'notifications', as: 'notification.created')]
 * class NotificationSent extends BaseBroadcastEvent {}
 * ```
 *
 * @example With multiple channels:
 * ```php
 * #[Broadcast(channels: ['notifications', 'admin-alerts'])]
 * class ImportantAlert extends BaseBroadcastEvent {}
 * ```
 *
 * @example With toOthers flag:
 * ```php
 * #[Broadcast(channels: 'chat-room', toOthers: true)]
 * class MessageSent extends BaseBroadcastEvent {}
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Broadcast
{
    /**
     * The channels to broadcast on
     *
     * @var array|string
     */
    public array|string $channels;

    /**
     * Custom event name (optional)
     * If not provided, the class name will be used
     *
     * @var string|null
     */
    public ?string $as;

    /**
     * Broadcast to others only (exclude current socket)
     *
     * @var bool
     */
    public bool $toOthers;

    /**
     * Create a new broadcast attribute
     *
     * @param array|string $channels The channel(s) to broadcast on
     * @param string|null $as Custom event name (defaults to class name)
     * @param bool $toOthers Whether to exclude the current user's socket
     */
    public function __construct(
        array|string $channels,
        ?string $as = null,
        bool $toOthers = false
    ) {
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