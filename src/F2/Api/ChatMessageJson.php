<?php

/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\F2\Api;

/**
 * JSON:API chat-message documents for Flarum 2 handlers.
 * Attribute names match js/src/forum/models/Message.js expectations.
 */
final class ChatMessageJson
{
    public static function resource($message): array
    {
        $chatId = (int) $message->chat_id;
        $userId = (int) $message->user_id;
        $body = (string) $message->message;

        return [
            'type' => 'chat-messages',
            'id' => (string) $message->id,
            'attributes' => [
                'message' => $body,
                'content' => $body,
                'chatId' => $chatId,
                'chat_id' => $chatId,
                'userId' => $userId,
                'created_at' => self::formatDate($message->created_at ?? null),
            ],
            'relationships' => [
                'chat' => [
                    'data' => [
                        'type' => 'live-rooms',
                        'id' => (string) $chatId,
                    ],
                ],
                'user' => [
                    'data' => [
                        'type' => 'users',
                        'id' => (string) $userId,
                    ],
                ],
            ],
        ];
    }

    public static function includedUser($user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'type' => 'users',
            'id' => (string) $user->id,
            'attributes' => [
                'username' => (string) $user->username,
                'displayName' => (string) ($user->display_name ?? $user->username),
                'avatarUrl' => $user->avatar_url ?? null,
            ],
        ];
    }

    private static function formatDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return (string) $value;
    }
}
