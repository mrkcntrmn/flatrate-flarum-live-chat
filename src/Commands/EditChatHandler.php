<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Commands;

use FlatRate\LiveChat\Auth\ChatAuthorization;

class EditChatHandler
{
    public function __construct(private ChatAuthorization $auth)
    {
    }

    public function handle(EditChat $command)
    {
        // Members cannot rename or mutate scope of canonical rooms.
        $this->auth->assertMemberCannotMutateRooms($command->actor);
        // Even admins: block scope/room_key mutation via this API in v1.
        $attrs = $command->data['attributes'] ?? [];
        foreach (['room_key', 'roomKey', 'scope_type', 'scopeType', 'scope_key', 'scopeKey'] as $forbidden) {
            if (array_key_exists($forbidden, $attrs)) {
                $this->auth->assertPrivateChatDisabled();
            }
        }
        // Title rename for admins could be allowed later via provisioner; reject freeform edit in B.
        $this->auth->assertPrivateChatDisabled();
    }
}
