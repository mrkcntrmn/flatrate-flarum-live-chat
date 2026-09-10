<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Auth;

use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;

/**
 * Server-side authorization for FlatRate live chat.
 *
 * Visibility/audience are independent dimensions (not a single enabled flag).
 * Hidden rooms are server-side: ordinary members must not see them in
 * list/API/history/realtime. Prefer 404 (repository filter) over revealing 403.
 */
class ChatAuthorization
{
    public const PERM_ENABLED = 'flatrate-live-chat.permissions.enabled';
    public const PERM_CHAT = 'flatrate-live-chat.permissions.chat';
    public const PERM_MODERATE = 'flatrate-live-chat.permissions.moderate';
    public const PERM_ADMIN_ROOMS = 'flatrate-live-chat.permissions.admin-rooms';

    public function assertEnabled(User $actor): void
    {
        $actor->assertCan(self::PERM_ENABLED);
    }

    public function assertNotGuest(User $actor): void
    {
        if (!$actor->id) {
            throw new PermissionDeniedException();
        }
    }

    public function assertNotSuspended(User $actor): void
    {
        $this->assertNotGuest($actor);
        if (method_exists($actor, 'isSuspended') && $actor->isSuspended()) {
            throw new PermissionDeniedException();
        }
        if (!empty($actor->suspended_until)) {
            $until = $actor->suspended_until;
            $ts = $until instanceof \DateTimeInterface ? $until->getTimestamp() : strtotime((string) $until);
            if ($ts && $ts > time()) {
                throw new PermissionDeniedException();
            }
        }
    }

    /**
     * Admin-only gate for operational probes (not merely "logged in").
     */
    public function assertAdmin(User $actor): void
    {
        $this->assertNotGuest($actor);
        $this->assertNotSuspended($actor);
        if (!$actor->isAdmin()) {
            throw new PermissionDeniedException();
        }
    }

    /**
     * Centralized staff-preview gate: admin + moderator.
     * Suspended users are denied even if they hold moderator permission.
     */
    public function canPreviewHiddenChatRooms(User $actor): bool
    {
        if (!$actor->id) {
            return false;
        }
        if (!empty($actor->suspended_until)) {
            $until = $actor->suspended_until;
            $ts = $until instanceof \DateTimeInterface ? $until->getTimestamp() : strtotime((string) $until);
            if ($ts && $ts > time()) {
                return false;
            }
        }
        if (method_exists($actor, 'isSuspended') && $actor->isSuspended()) {
            return false;
        }
        if ($actor->isAdmin()) {
            return true;
        }
        return $actor->can(self::PERM_MODERATE);
    }

    public function assertCanPreviewHiddenChatRooms(User $actor): void
    {
        if (!$this->canPreviewHiddenChatRooms($actor)) {
            throw new PermissionDeniedException();
        }
    }

    public function isRoomVisibleToActor(User $actor, Chat $chat): bool
    {
        if ((int) $chat->type !== 1 || !$chat->room_key) {
            return false;
        }
        $visibility = (string) ($chat->visibility ?? RoomVisibility::VISIBLE);
        $audience = (string) ($chat->audience ?? RoomAudience::MEMBERS);

        if ($visibility === RoomVisibility::VISIBLE && $audience === RoomAudience::MEMBERS) {
            return true;
        }

        // Hidden / staff-preview rooms: staff only.
        if ($visibility === RoomVisibility::HIDDEN || $audience === RoomAudience::STAFF_PREVIEW) {
            return $this->canPreviewHiddenChatRooms($actor);
        }

        return false;
    }

    public function assertCanListRooms(User $actor): void
    {
        $this->assertEnabled($actor);
    }

    public function assertCanReadRoom(User $actor, Chat $chat): void
    {
        $this->assertEnabled($actor);
        if ((int) $chat->type !== 1) {
            throw new PermissionDeniedException();
        }
        if (!$chat->room_key) {
            throw new PermissionDeniedException();
        }
        if (!$this->isRoomVisibleToActor($actor, $chat)) {
            // Callers that reach here for a filtered-out room should prefer 404.
            throw new PermissionDeniedException();
        }
    }

    public function assertCanReadMessages(User $actor, Chat $chat): void
    {
        $this->assertCanReadRoom($actor, $chat);
        if (!$actor->id) {
            throw new PermissionDeniedException();
        }
    }

    public function assertCanSubscribeRealtime(User $actor, Chat $chat): void
    {
        $this->assertCanReadMessages($actor, $chat);
        // Suspended may not subscribe (policy: denied posting/subscription).
        $this->assertNotSuspended($actor);
    }

    public function assertCanPost(User $actor, Chat $chat): void
    {
        $this->assertEnabled($actor);
        $this->assertNotSuspended($actor);
        $actor->assertCan(self::PERM_CHAT);
        $this->assertCanReadRoom($actor, $chat);
    }

    public function assertCanModerate(User $actor): void
    {
        $this->assertNotSuspended($actor);
        $actor->assertCan(self::PERM_MODERATE);
    }

    public function assertCanManageCanonicalRooms(User $actor): void
    {
        $this->assertNotSuspended($actor);
        if ($actor->isAdmin()) {
            return;
        }
        $actor->assertCan(self::PERM_ADMIN_ROOMS);
    }

    public function assertMemberCannotMutateRooms(User $actor): void
    {
        $this->assertCanManageCanonicalRooms($actor);
    }

    public function assertPrivateChatDisabled(): void
    {
        throw new PermissionDeniedException();
    }

    public function resolveSenderId(User $actor, array $attributes): int
    {
        $this->assertNotGuest($actor);
        if (array_key_exists('user_id', $attributes) && (int) $attributes['user_id'] !== (int) $actor->id) {
            throw new PermissionDeniedException();
        }
        if (array_key_exists('userId', $attributes) && (int) $attributes['userId'] !== (int) $actor->id) {
            throw new PermissionDeniedException();
        }
        return (int) $actor->id;
    }
}
