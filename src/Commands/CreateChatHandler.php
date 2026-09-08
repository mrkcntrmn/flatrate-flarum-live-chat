<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Commands;

use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\ChatUser;
use FlatRate\LiveChat\ChatValidator;
use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\EventMessageChatCreated;
use FlatRate\LiveChat\Commands\PostEventMessage;
use FlatRate\LiveChat\Event\Chat\Saved;
use FlatRate\LiveChat\Exceptions\ChatEditException;

class CreateChatHandler
{

    /**
     * @param ChatValidator $validator
     * @param ChatRepository $chats
     * @param BusDispatcher $bus
     * @param Dispatcher $events
     */
    public function __construct(ChatValidator $validator, ChatRepository $chats, BusDispatcher $bus, Dispatcher $events)
    {
        $this->validator = $validator;
        $this->chats  = $chats;
        $this->bus = $bus;
        $this->events = $events;
    }

    /**
     * Handles the command execution.
     *
     * @param CreateChat $command
     * @return null|string
     */
    public function handle(CreateChat $command)
    {
        $actor = $command->actor;
        $data = $command->data;
        $users = Arr::get($data, 'relationships.users.data', []);
        $attributes = Arr::get($data, 'attributes', []);
        $ip_address = $command->ip_address;

        $isChannel = intval($attributes['isChannel']);

        $actor->assertCan($isChannel ? 'flatrate-live-chat.permissions.create.channel' : 'flatrate-live-chat.permissions.create');

        $invited = [];

        foreach ($users as $key => $user) {
            if (array_key_exists($user['id'], $invited))
                throw new ChatEditException;

            $invited[$user['id']] = true;
            if ($user['id'] == $actor->id)
                array_splice($users, $key, 1);
        }
        array_push($users, ['id' => $actor->id, 'type' => 'users']);

        if (!$isChannel && count($users) < 2)
            throw new ChatEditException;

        if (count($users) == 2) {
            $chats = $this->chats->query()
                ->where('type', 0)
                ->whereIn('id', ChatUser::select('chat_id')->where('user_id', $actor->id)->get()->toArray())
                ->with('users')
                ->get();

            foreach ($chats as $chat) {
                $chatUsers = $chat->users;

                if (
                    count($chatUsers) == 2 &&
                    ($chatUsers[0]->id == $users[0]['id'] || $chatUsers[0]->id == $users[1]['id']) &&
                    ($chatUsers[1]->id == $users[0]['id'] || $chatUsers[1]->id == $users[1]['id'])
                )
                    throw new ChatEditException;
            }
        }

        $now = Carbon::now();
        $color = Arr::get($data, 'attributes.color', sprintf('#%06X', mt_rand(0x222222, 0xFFFF00)));
        $icon = Arr::get($data, 'attributes.icon', '');

        $chat = Chat::build(
            $attributes['title'],
            $color,
            $icon,
            $isChannel,
            $actor->id,
            Carbon::now()
        );

        $this->validator->assertValid($chat->getDirty());

        $chat->save();

        $user_ids = [];

        if (!$isChannel) {
            foreach ($users as $user) if ($user['id'] != $actor->id) $user_ids[] = $user['id'];

            $pairs = [];
            foreach (array_merge($user_ids, [$actor->id]) as $v) {
                $pairs[$v] = ['joined_at' => $now];
                if ($v == $actor->id) $pairs[$v]['role'] = 2;
            }

            try {
                $chat->users()->sync($pairs);
            } catch (Exception $e) {
                $chat->delete();
                throw $e;
            }
        } else {
            try {
                $chat->users()->sync([$actor->id => ['role' => 2, 'joined_at' => $now]]);
            } catch (Exception $e) {
                $chat->delete();
                throw $e;
            }
        }

        $eventMessage = $this->bus->dispatch(
            new PostEventMessage($chat->id, $actor, new EventMessageChatCreated($user_ids), $ip_address)
        );

        $this->events->dispatch(
            new Saved($chat, $actor, $data, true)
        );

        // Пользователь должен иметь возможность запретить приглашать себя куда либо

        return $chat;
    }
}
