<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Api;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\ChatRepository;
use Flarum\User\User;

/**
 * One requested room, authorized by the existing exact-room policy.
 * Does not subscribe, and does not list the hidden Brand catalog.
 */
class ExactRoomLookup
{
    public function __construct(
        private ChatRepository $chats,
        private ChatAuthorization $auth
    ) {
    }

    public function find(User $actor, string $roomKey): Chat
    {
        $this->auth->assertNotGuest($actor);
        $this->auth->assertNotSuspended($actor);

        $chat = $this->chats->findByRoomKeyOrFail($roomKey, $actor);
        $this->auth->assertCanReadRoom($actor, $chat);

        return $chat;
    }
}
