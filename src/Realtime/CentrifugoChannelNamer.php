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
 * Centrifugo private channel naming.
 * Format: $flatrate-live-<roomKey>
 * Never accepts arbitrary client-supplied channel names — subscription parse is catalog-strict.
 */
class CentrifugoChannelNamer
{
    public const PREFIX = '$flatrate-live-';

    /** @var array<string,true> */
    private array $knownRoomKeys = [];

    public function __construct(
        private ChatAuthorization $auth,
        private ?RoomCatalog $catalog = null
    ) {
        $this->catalog = $catalog ?? new RoomCatalog();
        foreach ($this->catalog->rooms() as $room) {
            $key = (string) ($room['roomKey'] ?? '');
            if ($key !== '') {
                $this->knownRoomKeys[$key] = true;
            }
        }
    }

    public function channelForRoomKey(string $roomKey): string
    {
        if (!$this->isValidRoomKeyFormat($roomKey)) {
            throw new PermissionDeniedException();
        }
        return self::PREFIX . $roomKey;
    }

    public function channelForRoom(Chat $chat): string
    {
        $roomKey = (string) ($chat->room_key ?? '');
        if ($roomKey === '') {
            throw new PermissionDeniedException();
        }
        return $this->channelForRoomKey($roomKey);
    }

    /**
     * Strict parse: only `$flatrate-live-<catalogRoomKey>`.
     * Never trust arbitrary client channel strings.
     */
    public function roomKeyFromChannel(string $channel): ?string
    {
        if (!str_starts_with($channel, self::PREFIX)) {
            return null;
        }
        $roomKey = substr($channel, strlen(self::PREFIX));
        if (!$this->isValidRoomKeyFormat($roomKey)) {
            return null;
        }
        if (!isset($this->knownRoomKeys[$roomKey])) {
            return null;
        }
        if ($channel !== self::PREFIX . $roomKey) {
            return null;
        }
        return $roomKey;
    }

    public function assertCanSubscribe(User $actor, Chat $chat): string
    {
        $this->auth->assertCanSubscribeRealtime($actor, $chat);
        $channel = $this->channelForRoom($chat);
        // Subscription minting requires the roomKey to resolve from a strict channel parse.
        if ($this->roomKeyFromChannel($channel) === null) {
            throw new PermissionDeniedException();
        }
        return $channel;
    }

    private function isValidRoomKeyFormat(string $roomKey): bool
    {
        return $roomKey !== '' && (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $roomKey);
    }
}
