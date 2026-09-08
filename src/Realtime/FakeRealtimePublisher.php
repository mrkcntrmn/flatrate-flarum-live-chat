<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Realtime;

/**
 * Disposable in-memory publisher for isolation tests.
 * SHARED_PUBLIC_PUSHER_CHANNEL=false — events are stored per room channel only.
 */
class FakeRealtimePublisher implements RealtimePublisher
{
    /** @var array<string, list<array{event:string,payload:array}>> */
    private array $channels = [];

    public function publish(string $roomChannelKey, string $event, array $payload): void
    {
        if (!isset($this->channels[$roomChannelKey])) {
            $this->channels[$roomChannelKey] = [];
        }
        $this->channels[$roomChannelKey][] = [
            'event' => $event,
            'payload' => $payload,
        ];
    }

    /**
     * @return list<array{event:string,payload:array}>
     */
    public function eventsFor(string $roomChannelKey): array
    {
        return $this->channels[$roomChannelKey] ?? [];
    }

    public function hasEvent(string $roomChannelKey, string $event): bool
    {
        foreach ($this->eventsFor($roomChannelKey) as $item) {
            if ($item['event'] === $event) {
                return true;
            }
        }
        return false;
    }

    public function reset(): void
    {
        $this->channels = [];
    }
}
