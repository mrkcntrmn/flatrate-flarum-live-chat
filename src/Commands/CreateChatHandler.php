<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Commands;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Exceptions\ChatEditException;

/**
 * Member-created rooms and type=0 private/group chats are disabled.
 * Canonical rooms are provisioned by admin reconcile only.
 */
class CreateChatHandler
{
    public function __construct(private ChatAuthorization $auth)
    {
    }

    public function handle(CreateChat $command)
    {
        $actor = $command->actor;
        $attributes = $command->data['attributes'] ?? [];
        $isChannel = intval($attributes['isChannel'] ?? $attributes['type'] ?? 1);

        // PRIVATE_PM_GROUP_DISABLE — reject type=0 / non-channel creates always.
        if (!$isChannel) {
            $this->auth->assertPrivateChatDisabled();
        }

        // USER_CREATED_CHANNEL_DISABLE — members cannot create rooms.
        $this->auth->assertMemberCannotMutateRooms($actor);

        throw new ChatEditException('Canonical rooms must be provisioned via RoomProvisioner; freeform create is disabled');
    }
}
