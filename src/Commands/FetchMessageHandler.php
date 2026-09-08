<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Commands;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\MessageRepository;

class FetchMessageHandler
{
    public function __construct(
        private MessageRepository $messages,
        private ChatRepository $chats,
        private ChatAuthorization $auth
    ) {
    }

    public function handle(FetchMessage $command)
    {
        $actor = $command->actor;
        $query = $command->query;

        $chat = $this->chats->findOrFail($command->chat_id, $actor);
        // GUEST_ENUMERATION — guests cannot fetch message history.
        $this->auth->assertCanReadMessages($actor, $chat);

        if (is_array($query)) {
            return $this->messages->queryVisible($chat, $actor)->whereIn('id', $query)->get();
        }

        return $this->messages->fetch($query, $actor, $chat);
    }
}
