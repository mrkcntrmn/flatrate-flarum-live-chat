<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Auth;

use FlatRate\LiveChat\Chat;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;

/**
 * Server-side authorization for FlatRate live chat.
 *
 * Guests cannot post. Members cannot create/rename/delete/mutate canonical rooms.
 * Suspended users cannot bypass. Sender identity always comes from the actor.
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
        // Flarum stores suspension_until; treat future timestamp as suspended.
        if (!empty($actor->suspended_until)) {
            $until = $actor->suspended_until;
            $ts = $until instanceof \DateTimeInterface ? $until->getTimestamp() : strtotime((string) $until);
            if ($ts && $ts > time()) {
                throw new PermissionDeniedException();
            }
        }
    }

    public function assertCanListRooms(User $actor): void
    {
        $this->assertEnabled($actor);
        // Guests may list public room metadata only (enforced in repository/serializers).
    }

    public function assertCanReadRoom(User $actor, Chat $chat): void
    {
        $this->assertEnabled($actor);
        if ((int) $chat->type !== 1) {
            // Private/group (type=0) is disabled for FlatRate.
            throw new PermissionDeniedException();
        }
        if (!$chat->room_key) {
            throw new PermissionDeniedException();
        }
    }

    public function assertCanReadMessages(User $actor, Chat $chat): void
    {
        $this->assertCanReadRoom($actor, $chat);
        // Initial policy: guests may see room metadata but not message history.
        if (!$actor->id) {
            throw new PermissionDeniedException();
        }
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
        // Members never create/rename/delete/mutate scope of rooms via API.
        // Only admins / explicit admin-rooms permission.
        $this->assertCanManageCanonicalRooms($actor);
    }

    public function assertPrivateChatDisabled(): void
    {
        throw new PermissionDeniedException();
    }

    /**
     * Sender must be the authenticated actor. Client-supplied user_id is ignored/rejected.
     */
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
