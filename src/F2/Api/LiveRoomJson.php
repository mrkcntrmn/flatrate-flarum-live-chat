<?php

namespace FlatRate\LiveChat\F2\Api;

/** Display metadata for an already-authorized room; never a member directory. */
final class LiveRoomJson
{
    public static function resource($chat): array
    {
        $created = $chat->created_at ?? null;

        return [
            'type' => 'live-rooms',
            'id' => (string) $chat->id,
            'attributes' => [
                'title' => (string) $chat->title,
                'color' => (string) ($chat->color ?? ''),
                'icon' => (string) ($chat->icon ?? ''),
                'type' => (int) $chat->type,
                'created_at' => $created instanceof \DateTimeInterface ? $created->format(\DateTimeInterface::ATOM) : ($created === null ? null : (string) $created),
                'roomKey' => (string) $chat->room_key,
                'room_key' => (string) $chat->room_key,
                'scope_type' => (string) ($chat->scope_type ?? ''),
                'scope_key' => (string) ($chat->scope_key ?? ''),
                'visibility' => (string) ($chat->visibility ?? ''),
                'audience' => (string) ($chat->audience ?? ''),
            ],
            // The browser model expects a collection, but room participants
            // are private. Presence remains the separate count-only API.
            'relationships' => ['users' => ['data' => []]],
        ];
    }
}
