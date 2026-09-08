<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Catalog;

/**
 * Embedded room catalog — no runtime wiki filesystem dependency.
 */
class RoomCatalog
{
    private array $document;

    public function __construct(?string $catalogPath = null)
    {
        $path = $catalogPath ?? dirname(__DIR__, 2) . '/resources/room-catalog.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('room catalog missing: ' . $path);
        }
        $doc = json_decode($raw, true);
        if (!is_array($doc)) {
            throw new \RuntimeException('room catalog invalid JSON');
        }
        $this->document = $doc;
    }

    public function document(): array
    {
        return $this->document;
    }

    /** @return list<array{roomKey:string,name:string,scopeType:string,scopeKey:string}> */
    public function rooms(): array
    {
        return $this->document['rooms'] ?? [];
    }

    public function brandRoomCount(): int
    {
        return (int) ($this->document['brandRoomCount'] ?? 0);
    }

    public function generalRoomCount(): int
    {
        return (int) ($this->document['generalRoomCount'] ?? 0);
    }

    public function totalRoomCount(): int
    {
        return (int) ($this->document['totalRoomCount'] ?? 0);
    }

    public function findByRoomKey(string $roomKey): ?array
    {
        foreach ($this->rooms() as $room) {
            if (($room['roomKey'] ?? null) === $roomKey) {
                return $room;
            }
        }
        return null;
    }
}
