<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Commands;

use FlatRate\LiveChat\Auth\ChatAuthorization;

class DeleteChatHandler
{
    public function __construct(private ChatAuthorization $auth)
    {
    }

    public function handle(DeleteChat $command)
    {
        $this->auth->assertMemberCannotMutateRooms($command->actor);
        // Canonical room delete is not exposed via member/admin freeform API in B.
        $this->auth->assertPrivateChatDisabled();
    }
}
