<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ChatRepository
{
    public function __construct(private ChatAuthorization $auth)
    {
    }

    public function query()
    {
        return Chat::query();
    }

    /**
     * Visible rooms for actor under rollout policy.
     * Ordinary members: visible+members only.
     * Staff preview: also hidden/staff-preview.
     * Prefer empty/404 over revealing hidden rooms.
     */
    public function queryVisible(User $actor)
    {
        $this->auth->assertCanListRooms($actor);

        $query = $this->query()
            ->where('type', 1)
            ->whereNotNull('room_key');

        if ($this->auth->canPreviewHiddenChatRooms($actor)) {
            return $query;
        }

        return $query
            ->where('visibility', RoomVisibility::VISIBLE)
            ->where('audience', RoomAudience::MEMBERS);
    }

    public function findOrFail($id, $actor)
    {
        $chat = $this->queryVisible($actor)->find($id);
        if (!$chat) {
            throw (new ModelNotFoundException())->setModel(Chat::class, [$id]);
        }
        return $chat;
    }

    public function findByRoomKeyOrFail(string $roomKey, User $actor): Chat
    {
        $chat = $this->queryVisible($actor)->where('room_key', $roomKey)->first();
        if (!$chat) {
            throw (new ModelNotFoundException())->setModel(Chat::class, [$roomKey]);
        }
        return $chat;
    }

    /**
     * Live Chats directory: General (if authorized) first, then active
     * subscriptions that remain visible under current policy.
     *
     * @return list<Chat>
     */
    public function listLiveDirectory(User $actor): array
    {
        $general = $this->queryVisible($actor)
            ->with(['last_message'])
            ->where('room_key', 'community-general-live')
            ->first();

        $subscribed = $this->queryVisible($actor)
            ->where('room_key', '!=', 'community-general-live')
            ->whereExists(function ($q) use ($actor) {
                $q->selectRaw('1')
                    ->from('neonchat_chat_user')
                    ->whereColumn('neonchat_chat_user.chat_id', 'neonchat_chats.id')
                    ->where('neonchat_chat_user.user_id', $actor->id)
                    ->whereNull('neonchat_chat_user.removed_at');
            })
            ->with(['last_message'])
            ->get()
            ->sort(function (Chat $a, Chat $b) {
                $aId = $a->last_message ? (int) $a->last_message->id : 0;
                $bId = $b->last_message ? (int) $b->last_message->id : 0;
                if ($aId !== $bId) {
                    return $bId <=> $aId;
                }
                return strcmp((string) $a->title, (string) $b->title);
            })
            ->values();

        $out = [];
        if ($general) {
            $out[] = $general;
        }
        foreach ($subscribed as $chat) {
            $out[] = $chat;
        }

        return $out;
    }
}
