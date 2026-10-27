<?php

/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\F2\Listener;

use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\ChatSocket;
use FlatRate\LiveChat\Event\Chat\Deleting as ChatDeleting;
use FlatRate\LiveChat\Event\Chat\Saved as ChatSaved;
use FlatRate\LiveChat\Event\Message\Deleting as MessageDeleting;
use FlatRate\LiveChat\Event\Message\Saved as MessageSaved;
use FlatRate\LiveChat\Message;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Flarum 2 realtime publisher — no Tobscure / F1 JSON:API serializers.
 * Room channel is always resolved server-side via Chat model / room_key.
 */
class PushChatEvents
{
    public function __construct(private ChatSocket $socket)
    {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(ChatSaved::class, [$this, 'whenChatSaved']);
        $events->listen(ChatDeleting::class, [$this, 'whenChatDeleting']);
        $events->listen(MessageSaved::class, [$this, 'whenMessageSaved']);
        $events->listen(MessageDeleting::class, [$this, 'whenMessageDeleting']);
    }

    public function whenChatSaved(ChatSaved $event): void
    {
        $chat = $event->chat;
        $payload = [
            'chat' => $this->chatDocument($chat, $event->actor),
        ];

        if ($event->created) {
            $type = 'chat.create';
        } else {
            $type = 'chat.edit';
            $payload['eventmsg_range'] = $chat->eventmsg_range ?? null;
            $payload['roles_updated_for'] = $chat->roles_updated_for ?? null;
        }

        $this->socket->sendChatEvent($chat->id, $type, $payload, $chat);
    }

    public function whenChatDeleting(ChatDeleting $event): void
    {
        $chat = $event->chat;
        $this->socket->sendChatEvent($chat->id, 'chat.delete', [
            'chat' => $this->chatDocument($chat, $event->actor),
        ], $chat);
    }

    public function whenMessageSaved(MessageSaved $event): void
    {
        $message = $event->message;
        $chat = $this->resolveChatForMessage($message);
        $type = $event->created ? 'message.post' : 'message.edit';

        $this->socket->sendChatEvent($message->chat_id, $type, [
            'message' => $this->messageDocument($message, $event->actor, $chat),
        ], $chat);
    }

    public function whenMessageDeleting(MessageDeleting $event): void
    {
        $message = $event->message;
        $chat = $this->resolveChatForMessage($message);

        $this->socket->sendChatEvent($message->chat_id, 'message.delete', [
            'message' => $this->messageDocument($message, $event->actor, $chat),
        ], $chat);
    }

    /**
     * Never trust client channel names — resolve room from the Chat model.
     */
    private function resolveChatForMessage(Message $message): ?Chat
    {
        $related = $message->chat ?? null;
        if ($related instanceof Chat && !empty($related->room_key)) {
            return $related;
        }

        $chatId = (int) ($message->chat_id ?? 0);
        if ($chatId <= 0) {
            return null;
        }

        return Chat::find($chatId);
    }

    /**
     * @return array{data: array{type: string, id: string, attributes: array<string, mixed>}}
     */
    private function messageDocument(Message $message, User $actor, ?Chat $chat): array
    {
        $deletedBy = $this->scalarAttribute($message, 'deleted_by');

        $attributes = [
            'message' => (string) ($message->message ?? ''),
            'type' => (int) ($message->type ?? 0),
            'is_readed' => (bool) ($message->is_readed ?? false),
            'created_at' => $this->formatDate($message->created_at ?? null),
            'edited_at' => $this->formatDate($message->edited_at ?? null),
            'chat_id' => (int) ($message->chat_id ?? 0),
            'user_id' => (int) ($message->user_id ?? 0),
            'actor_id' => (int) ($actor->id ?? 0),
            'deleted_by' => $deletedBy !== null && $deletedBy !== '' ? (int) $deletedBy : null,
        ];

        if ($chat && !empty($chat->room_key)) {
            $attributes['room_key'] = (string) $chat->room_key;
        }

        return [
            'data' => [
                'type' => 'chatmessages',
                'id' => (string) ($message->id ?? ''),
                'attributes' => $attributes,
            ],
        ];
    }

    /**
     * @return array{data: array{type: string, id: string, attributes: array<string, mixed>}}
     */
    private function chatDocument(Chat $chat, User $actor): array
    {
        return [
            'data' => [
                'type' => 'chats',
                'id' => (string) ($chat->id ?? ''),
                'attributes' => [
                    'title' => (string) ($chat->title ?? ''),
                    'color' => (string) ($chat->color ?? ''),
                    'icon' => (string) ($chat->icon ?? ''),
                    'type' => (int) ($chat->type ?? 0),
                    'room_key' => (string) ($chat->room_key ?? ''),
                    'scope_type' => (string) ($chat->scope_type ?? ''),
                    'scope_key' => (string) ($chat->scope_key ?? ''),
                    'visibility' => (string) ($chat->visibility ?? ''),
                    'audience' => (string) ($chat->audience ?? ''),
                    'created_at' => $this->formatDate($chat->created_at ?? null),
                    'actor_id' => (int) ($actor->id ?? 0),
                ],
            ],
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Prefer raw attributes when a same-named Eloquent relation exists (e.g. deleted_by).
     */
    private function scalarAttribute(object $model, string $key): mixed
    {
        if (method_exists($model, 'getAttributes')) {
            $attrs = $model->getAttributes();
            if (is_array($attrs) && array_key_exists($key, $attrs)) {
                return $attrs[$key];
            }
        }

        $value = $model->{$key} ?? null;
        if (is_object($value) && isset($value->id)) {
            return $value->id;
        }

        return $value;
    }
}
