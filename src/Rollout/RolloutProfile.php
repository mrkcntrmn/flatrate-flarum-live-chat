<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Rollout;

/**
 * Canonical/visibility/audience dimensions — never a single enabled boolean.
 */
class RolloutProfile
{
    public const PROFILE_GENERAL_LIVE_FIRST = 'general-live-first';

    private array $document;

    public function __construct(?string $path = null)
    {
        $path = $path ?? dirname(__DIR__, 2) . '/resources/rollout/general-live-first.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('rollout profile missing: ' . $path);
        }
        $doc = json_decode($raw, true);
        if (!is_array($doc)) {
            throw new \RuntimeException('rollout profile invalid JSON');
        }
        $this->document = $doc;
    }

    public function document(): array
    {
        return $this->document;
    }

    public function profileId(): string
    {
        return (string) ($this->document['profileId'] ?? '');
    }

    public function canonicalRoomCount(): int
    {
        return (int) ($this->document['canonicalRoomCount'] ?? 0);
    }

    /**
     * @return array{visibility:string,audience:string}
     */
    public function policyForRoomKey(string $roomKey): array
    {
        foreach ($this->document['rooms'] ?? [] as $room) {
            if (($room['roomKey'] ?? null) === $roomKey) {
                return [
                    'visibility' => (string) $room['visibility'],
                    'audience' => (string) $room['audience'],
                ];
            }
        }
        $default = $this->document['defaultBrand'] ?? [];
        return [
            'visibility' => (string) ($default['visibility'] ?? RoomVisibility::HIDDEN),
            'audience' => (string) ($default['audience'] ?? RoomAudience::STAFF_PREVIEW),
        ];
    }

    /**
     * Expand policies for a catalog of rooms.
     *
     * @param list<array{roomKey:string}> $rooms
     * @return list<array{roomKey:string,visibility:string,audience:string}>
     */
    public function expandForRooms(array $rooms): array
    {
        $out = [];
        foreach ($rooms as $room) {
            $key = (string) ($room['roomKey'] ?? '');
            if ($key === '') {
                continue;
            }
            $policy = $this->policyForRoomKey($key);
            $out[] = [
                'roomKey' => $key,
                'visibility' => $policy['visibility'],
                'audience' => $policy['audience'],
            ];
        }
        return $out;
    }

    public function assertValid(): void
    {
        if ($this->profileId() !== self::PROFILE_GENERAL_LIVE_FIRST) {
            throw new \RuntimeException('unsupported rollout profile: ' . $this->profileId());
        }
        if ($this->canonicalRoomCount() !== 42) {
            throw new \RuntimeException('CANONICAL_ROOM_COUNT must be 42');
        }
        $general = $this->policyForRoomKey('community-general-live');
        if ($general['visibility'] !== RoomVisibility::VISIBLE || $general['audience'] !== RoomAudience::MEMBERS) {
            throw new \RuntimeException('community-general-live must be visible/members');
        }
        $toyota = $this->policyForRoomKey('toyota-live');
        if ($toyota['visibility'] !== RoomVisibility::HIDDEN || $toyota['audience'] !== RoomAudience::STAFF_PREVIEW) {
            throw new \RuntimeException('brand rooms must be hidden/staff-preview under general-live-first');
        }
    }
}
