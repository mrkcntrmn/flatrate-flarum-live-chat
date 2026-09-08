<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

/**
 * Versioned FlatRate realtime envelope with allowlisted payload fields.
 */
final class EventEnvelope
{
    public const VERSION = 1;

    public const TYPE_MESSAGE_CREATED = 'message.created';
    public const TYPE_MESSAGE_EDITED = 'message.edited';
    public const TYPE_MESSAGE_DELETED = 'message.deleted';
    public const TYPE_ROOM_UPDATED = 'room.updated';

    private const ALLOWED_PAYLOAD_KEYS = [
        'messageId',
        'roomKey',
        'userId',
        'order',
        'createdAt',
        'editedAt',
        'deletedAt',
        'title',
        'visibility',
        'audience',
    ];

    /**
     * @param array<string,mixed> $payload
     * @return array{v:int,type:string,roomKey:string,order:?int,payload:array<string,mixed>}
     */
    public static function make(string $type, string $roomKey, array $payload, ?int $order = null): array
    {
        $safe = [];
        foreach (self::ALLOWED_PAYLOAD_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                $safe[$key] = $payload[$key];
            }
        }
        // Always echo roomKey in payload for clients that only read payload.
        $safe['roomKey'] = $roomKey;

        return [
            'v' => self::VERSION,
            'type' => $type,
            'roomKey' => $roomKey,
            'order' => $order ?? ($safe['order'] ?? null),
            'payload' => $safe,
        ];
    }

    public static function mapLegacyEvent(string $legacy): ?string
    {
        return match ($legacy) {
            'message.post', 'RoomMessageCreated' => self::TYPE_MESSAGE_CREATED,
            'message.edit' => self::TYPE_MESSAGE_EDITED,
            'message.delete' => self::TYPE_MESSAGE_DELETED,
            'chat.edit', 'chat.create', 'chat.delete', 'room.updated' => self::TYPE_ROOM_UPDATED,
            self::TYPE_MESSAGE_CREATED,
            self::TYPE_MESSAGE_EDITED,
            self::TYPE_MESSAGE_DELETED,
            self::TYPE_ROOM_UPDATED => $legacy,
            default => null,
        };
    }
}
