<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Commands;

use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\MessageRepository;

class FetchMessageHandler
{
    /**
     * @var MessageRepository
     */
    protected $messages;

    /**
     * @param MessageRepository             $messages
     * @param ChatRepository                $chats
     */
    public function __construct(
        MessageRepository $messages,
        ChatRepository $chats) 
    {
        $this->messages  = $messages;
        $this->chats = $chats;
    }

    /**
     * Handles the command execution.
     *
     * @return null|string
     *
     */
    public function handle(FetchMessage $command)
    {
        $actor = $command->actor;
        $query = $command->query;
 
        $chat = $this->chats->findOrFail($command->chat_id, $actor);

        if(is_array($query)) $messages = $this->messages->queryVisible($chat, $actor)->whereIn('id', $query)->get();
        else $messages = $this->messages->fetch($query, $actor, $chat);

        return $messages;
    }
}
