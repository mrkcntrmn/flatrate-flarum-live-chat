<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

/**
 * No-op publisher used when Pusher credentials are incomplete (fail-closed)
 * or when transport is intentionally disabled.
 */
class NullRealtimePublisher implements RealtimePublisher
{
    public function publish(string $roomChannelKey, string $event, array $payload): void
    {
        // Intentionally empty — diagnostic only via doctor/logs.
    }
}
