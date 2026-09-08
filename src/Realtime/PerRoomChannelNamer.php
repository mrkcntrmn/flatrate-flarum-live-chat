<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;

/**
 * Per-room isolated private channel naming.
 * Format: private-flatrate-live-<deterministic-from-roomKey>
 * Never uses a shared "public" fanout channel.
 */
class PerRoomChannelNamer
{
    /** @var array<string,string> channelSuffix => roomKey */
    private array $suffixIndex = [];

    public function __construct(
        private ChatAuthorization $auth,
        private ?RoomCatalog $catalog = null
    ) {
        $this->catalog = $catalog ?? new RoomCatalog();
        foreach ($this->catalog->rooms() as $room) {
            $key = (string) $room['roomKey'];
            $this->suffixIndex[$this->suffixForRoomKey($key)] = $key;
        }
    }

    public function channelKeyForRoom(Chat $chat): string
    {
        $roomKey = (string) ($chat->room_key ?? '');
        if ($roomKey === '') {
            throw new PermissionDeniedException();
        }
        return $this->channelKeyForRoomKey($roomKey);
    }

    public function channelKeyForRoomKey(string $roomKey): string
    {
        return 'private-flatrate-live-' . $this->suffixForRoomKey($roomKey);
    }

    public function suffixForRoomKey(string $roomKey): string
    {
        return hash('sha256', 'flatrate-live-chat|' . $roomKey);
    }

    public function roomKeyFromChannel(string $channelName): ?string
    {
        $prefix = 'private-flatrate-live-';
        if (!str_starts_with($channelName, $prefix)) {
            return null;
        }
        $suffix = substr($channelName, strlen($prefix));
        return $this->suffixIndex[$suffix] ?? null;
    }

    public function assertCanSubscribe(User $actor, Chat $chat): string
    {
        $this->auth->assertCanSubscribeRealtime($actor, $chat);
        return $this->channelKeyForRoom($chat);
    }
}
