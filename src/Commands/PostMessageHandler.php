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
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\Event\Message\Saved;
use FlatRate\LiveChat\Message;
use FlatRate\LiveChat\MessageValidator;

/**
 * Persist then dispatch Saved. Realtime publication is owned solely by
 * PushChatEvents → ChatSocket (one emission authority).
 */
class PostMessageHandler
{
    public function __construct(
        private MessageValidator $validator,
        private ChatRepository $chats,
        private Dispatcher $events,
        private ChatAuthorization $auth
    ) {
    }

    public function handle(PostMessage $command)
    {
        $actor = $command->actor;
        $attributes = $command->data['attributes'] ?? [];

        $content = $attributes['message'] ?? '';
        $chat_id = $attributes['chat_id'] ?? null;

        $senderId = $this->auth->resolveSenderId($actor, $attributes);

        $chat = $this->chats->findOrFail($chat_id, $actor);
        $this->auth->assertCanPost($actor, $chat);

        // Explicit membership for posting only — never on read.
        $chat->ensureMembership($actor);
        $chatUser = $chat->getChatUser($actor);
        $actor->assertPermission($chatUser && !$chatUser->removed_at);

        $message = Message::build(
            $content,
            $senderId,
            Carbon::now(),
            $chat->id,
            null // CHAT_IP_PERSISTENCE=false
        );

        $this->validator->assertValid($message->getDirty());
        $message->save();

        $chat->users()->updateExistingPivot($actor->id, ['readed_at' => Carbon::now()]);

        // Domain event → PushChatEvents → ChatSocket → RealtimePublisher (best-effort).
        $this->events->dispatch(
            new Saved($message, $actor, $command->data, true)
        );

        return $message;
    }
}
