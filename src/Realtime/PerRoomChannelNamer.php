<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Realtime;

use FlatRate\LiveChat\Chat;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use FlatRate\LiveChat\Auth\ChatAuthorization;

/**
 * Per-room isolated channel naming + subscribe authorization.
 * Never uses a shared "public" fanout channel.
 */
class PerRoomChannelNamer
{
    public function __construct(private ChatAuthorization $auth)
    {
    }

    /**
     * Opaque channel key derived from durable room_key, not Neon numeric id alone.
     */
    public function channelKeyForRoom(Chat $chat): string
    {
        $roomKey = (string) ($chat->room_key ?? '');
        if ($roomKey === '') {
            throw new PermissionDeniedException();
        }
        // HMAC-like opaque-ish token without requiring secrets in unit tests:
        // prefix + sha256(room_key) keeps names non-guessable as authz mechanism.
        return 'private-flatrate-chat-room-' . hash('sha256', 'flatrate-live-chat|' . $roomKey);
    }

    public function assertCanSubscribe(User $actor, Chat $chat): string
    {
        $this->auth->assertCanReadRoom($actor, $chat);
        // Guests cannot subscribe to realtime streams in v1.
        if (!$actor->id) {
            throw new PermissionDeniedException();
        }
        return $this->channelKeyForRoom($chat);
    }
}
