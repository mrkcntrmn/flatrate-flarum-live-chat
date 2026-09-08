<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Realtime;

/**
 * No-op publisher. Real transport selection remains PENDING
 * (REALTIME_IMPLEMENTATION_DECISION=PENDING; PUSHER_SELECTED=false).
 */
class NullRealtimePublisher implements RealtimePublisher
{
    public function publish(string $roomChannelKey, string $event, array $payload): void
    {
        // Intentionally empty.
    }
}
