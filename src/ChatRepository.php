<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Auth\GeneralLiveGate;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

        if (!$this->auth->isGeneralLiveEnabled()) {
            $query->where('room_key', '!=', GeneralLiveGate::ROOM_KEY);
        }

        if ($this->auth->canPreviewHiddenChatRooms($actor)) {
            return $query;
        }

        return $query
            ->where('visibility', RoomVisibility::VISIBLE)
            ->where('audience', RoomAudience::MEMBERS);
    }

    public function findOrFail($id, $actor)
    {
        $this->assertActorCanList($actor);
        $listed = $this->listedRoomById($actor, $id);
        if ($listed instanceof Chat) {
            return $listed;
        }

        return $this->retrieveExactAuthorizedHiddenRoom($actor, $this->storedRoomById($id), $id);
    }

    public function findByRoomKeyOrFail(string $roomKey, User $actor): Chat
    {
        $this->assertActorCanList($actor);
        $listed = $this->listedRoomByKey($actor, $roomKey);
        if ($listed instanceof Chat) {
            return $listed;
        }

        return $this->retrieveExactAuthorizedHiddenRoom($actor, $this->storedRoomByKey($roomKey), $roomKey);
    }

    /**
     * List/catalog queries miss hidden brand rooms for non-staff actors.
     * Single-room retrieval may still return one exact authorized brand room.
     * Anything else stays a 404 so hidden rooms are not revealed.
     *
     * @param mixed $identity
     */
    public function retrieveExactAuthorizedHiddenRoom(User $actor, ?Chat $candidate, $identity): Chat
    {
        if ($candidate instanceof Chat && $this->isExactAuthorizedHiddenBrandRoom($actor, $candidate)) {
            return $candidate;
        }

        throw (new ModelNotFoundException())->setModel(Chat::class, [$identity]);
    }

    public function isExactAuthorizedHiddenBrandRoom(User $actor, Chat $chat): bool
    {
        if ((int) $chat->type !== 1 || !$chat->room_key) {
            return false;
        }
        $visibility = (string) ($chat->visibility ?? '');
        $audience = (string) ($chat->audience ?? '');
        $hidden = $visibility === RoomVisibility::HIDDEN || $audience === RoomAudience::STAFF_PREVIEW;
        if (!$hidden) {
            return false;
        }

        return $this->auth->isRoomVisibleToActor($actor, $chat);
    }

    /**
     * Directory addition for a hidden room the visible-list query missed.
     * Unsubscribed rooms stay out. Staff preview already receives hidden rooms
     * from queryVisible, so this path does not route moderators through beta.
     */
    public function hiddenSubscriptionEligible(User $actor, Chat $chat, bool $activeSubscription): bool
    {
        if (!$activeSubscription) {
            return false;
        }
        if ($this->auth->canPreviewHiddenChatRooms($actor)) {
            return false;
        }

        return $this->isExactAuthorizedHiddenBrandRoom($actor, $chat);
    }

    protected function assertActorCanList(User $actor): void
    {
        $this->auth->assertCanListRooms($actor);
    }

    protected function listedRoomById(User $actor, $id): ?Chat
    {
        $chat = $this->queryVisible($actor)->find($id);

        return $chat instanceof Chat ? $chat : null;
    }

    protected function listedRoomByKey(User $actor, string $roomKey): ?Chat
    {
        $chat = $this->queryVisible($actor)->where('room_key', $roomKey)->first();

        return $chat instanceof Chat ? $chat : null;
    }

    protected function storedRoomById($id): ?Chat
    {
        $chat = $this->query()->where('type', 1)->whereNotNull('room_key')->find($id);

        return $chat instanceof Chat ? $chat : null;
    }

    protected function storedRoomByKey(string $roomKey): ?Chat
    {
        $chat = $this->query()->where('type', 1)->where('room_key', $roomKey)->first();

        return $chat instanceof Chat ? $chat : null;
    }

    /**
     * Live Chats directory: General (if authorized) first, then active
     * subscriptions that remain visible under current policy.
     *
     * @return EloquentCollection<int, Chat>
     */
    public function listLiveDirectory(User $actor): EloquentCollection
    {
        $general = $this->queryVisible($actor)
            ->with(['last_message'])
            ->where('room_key', 'community-general-live')
            ->first();

        $subscribed = $this->activeSubscriptionQuery(
            $this->queryVisible($actor)->where('room_key', '!=', 'community-general-live'),
            $actor
        )
            ->with(['last_message'])
            ->get();

        if (
            !$this->auth->canPreviewHiddenChatRooms($actor)
            && $this->auth->allowsApprovedMemberBeta($actor)
        ) {
            $hiddenQuery = $this->query()
                ->where('type', 1)
                ->whereNotNull('room_key')
                ->where('room_key', '!=', 'community-general-live')
                ->where(function ($q) {
                    $q->where('visibility', RoomVisibility::HIDDEN)
                        ->orWhere('audience', RoomAudience::STAFF_PREVIEW);
                });
            $hiddenSubs = $this->activeSubscriptionQuery($hiddenQuery, $actor)
                ->with(['last_message'])
                ->get();
            $seen = [];
            foreach ($subscribed as $chat) {
                $seen[(int) $chat->id] = true;
            }
            foreach ($hiddenSubs as $chat) {
                if (!$chat instanceof Chat || isset($seen[(int) $chat->id])) {
                    continue;
                }
                if ($this->hiddenSubscriptionEligible($actor, $chat, true)) {
                    $subscribed->push($chat);
                    $seen[(int) $chat->id] = true;
                }
            }
        }

        $subscribed = $this->sortLiveDirectorySubscriptions($subscribed);

        return $this->assembleLiveDirectory($general instanceof Chat ? $general : null, $subscribed);
    }

    private function activeSubscriptionQuery($query, User $actor)
    {
        return $query->whereExists(function ($q) use ($actor) {
            $q->selectRaw('1')
                ->from('neonchat_chat_user')
                ->whereColumn('neonchat_chat_user.chat_id', 'neonchat_chats.id')
                ->where('neonchat_chat_user.user_id', $actor->id)
                ->whereNull('neonchat_chat_user.removed_at');
        });
    }

    private function sortLiveDirectorySubscriptions(EloquentCollection $subscribed): EloquentCollection
    {
        return $subscribed->sort(function (Chat $a, Chat $b) {
            $aId = $a->last_message ? (int) $a->last_message->id : 0;
            $bId = $b->last_message ? (int) $b->last_message->id : 0;
            if ($aId !== $bId) {
                return $bId <=> $aId;
            }
            return strcmp((string) $a->title, (string) $b->title);
        })->values();
    }

    /**
     * Keep Eloquent collection identity through the API serializer boundary.
     *
     * @param EloquentCollection<int, Chat> $subscribed
     * @return EloquentCollection<int, Chat>
     */
    public function assembleLiveDirectory(?Chat $general, EloquentCollection $subscribed): EloquentCollection
    {
        $items = [];
        if ($general !== null) {
            $items[] = $general;
        }
        foreach ($subscribed as $chat) {
            if ($general !== null && (int) $chat->id === (int) $general->id) {
                continue;
            }
            $items[] = $chat;
        }

        return new EloquentCollection($items);
    }
}
