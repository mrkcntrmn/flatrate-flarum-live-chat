<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Commands;

use Carbon\Carbon;
use Illuminate\Contracts\Events\Dispatcher;
use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\Event\Message\Saved;
use FlatRate\LiveChat\Message;
use FlatRate\LiveChat\MessageValidator;

class PostMessageHandler
{
    /**
     * @var MessageValidator
     */
    protected $validator;

    /**
     * @param MessageValidator      $validator
     * @param ChatRepository        $chats
     * @param Dispatcher            $events
     */
    public function __construct(
        MessageValidator $validator,
        ChatRepository $chats,
        Dispatcher $events
    ) {
        $this->validator = $validator;
        $this->chats = $chats;
        $this->events = $events;
    }

    /**
     * Handles the command execution.
     *
     * @param PostMessage $command
     * @return null|string
     */
    public function handle(PostMessage $command)
    {
        $actor = $command->actor;
        $attributes = $command->data['attributes'];
        $ip_address = $command->ip_address;

        $content = $attributes['message'];
        $chat_id = $attributes['chat_id'];

        $chat = $this->chats->findOrFail($chat_id, $actor);

        $actor->assertCan('flatrate-live-chat.permissions.chat');

        $chatUser = $chat->getChatUser($actor);

        $actor->assertPermission($chatUser && !$chatUser->removed_at);

        $message = Message::build(
            $content,
            $actor->id,
            Carbon::now(),
            $chat->id,
            $ip_address
        );

        $this->validator->assertValid($message->getDirty());

        $message->save();

        $chat->users()->updateExistingPivot($actor->id, ['readed_at' => Carbon::now()]);

        $this->events->dispatch(
            new Saved($message, $actor, $command->data, true)
        );

        return $message;
    }
}
