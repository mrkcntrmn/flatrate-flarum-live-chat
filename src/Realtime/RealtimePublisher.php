<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Realtime;

interface RealtimePublisher
{
    /**
     * Publish a domain event to an isolated per-room channel.
     *
     * @param string $roomChannelKey Opaque/authorized channel key for one room
     * @param string $event Domain event name
     * @param array<string,mixed> $payload
     */
    public function publish(string $roomChannelKey, string $event, array $payload): void;
}
