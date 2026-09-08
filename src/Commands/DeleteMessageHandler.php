<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Commands;

use Illuminate\Contracts\Events\Dispatcher;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\Event\Message\Deleting;
use FlatRate\LiveChat\MessageRepository;

class DeleteMessageHandler
{
    public function __construct(
        private MessageRepository $messages,
        private ChatRepository $chats,
        private Dispatcher $events,
        private ChatAuthorization $auth
    ) {
    }

    public function handle(DeleteMessage $command)
    {
        $actor = $command->actor;
        $message = $this->messages->findOrFail($command->id);

        $actor->assertPermission(!$message->type);

        $chat = $this->chats->findOrFail($message->chat_id, $actor);

        $isOwner = (int) $message->user_id === (int) $actor->id;
        if (!$isOwner) {
            $this->auth->assertCanModerate($actor);
        } else {
            $this->auth->assertNotSuspended($actor);
            $this->auth->assertEnabled($actor);
        }

        $this->events->dispatch(new Deleting($message, $actor));

        $message->deleted_by = $actor->id;
        $message->save();
        $message->delete();

        return $message;
    }
}
