<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

class ChatRepository
{
    public function __construct(private ChatAuthorization $auth)
    {
    }

    public function query()
    {
        return Chat::query();
    }

    /**
     * Visible rooms: canonical public rooms (type=1 with room_key).
     * type=0 private/group excluded. Guests see metadata list only via serializer policy.
     */
    public function queryVisible(User $actor)
    {
        $this->auth->assertCanListRooms($actor);

        return $this->query()
            ->where('type', 1)
            ->whereNotNull('room_key');
    }

    public function findOrFail($id, $actor)
    {
        $chat = $this->queryVisible($actor)->findOrFail($id);
        $this->auth->assertCanReadRoom($actor, $chat);
        return $chat;
    }

    public function findByRoomKeyOrFail(string $roomKey, User $actor): Chat
    {
        $chat = $this->queryVisible($actor)->where('room_key', $roomKey)->firstOrFail();
        $this->auth->assertCanReadRoom($actor, $chat);
        return $chat;
    }
}
