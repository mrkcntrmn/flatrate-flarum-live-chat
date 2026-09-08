<?php

use Flarum\Group\Group;
use Flarum\Database\Migration;


return Migration::addPermissions([
    'flatrate-live-chat.permissions.enabled' => [Group::GUEST_ID],

    'flatrate-live-chat.permissions.chat' => [Group::MEMBER_ID],
    'flatrate-live-chat.permissions.create' => [Group::MEMBER_ID],
    'flatrate-live-chat.permissions.edit' => [Group::MEMBER_ID],
    'flatrate-live-chat.permissions.delete' => [Group::MEMBER_ID],

    'flatrate-live-chat.permissions.create.channel' => [Group::MODERATOR_ID],
    'flatrate-live-chat.permissions.moderate.delete' => [Group::MODERATOR_ID],
    'flatrate-live-chat.permissions.moderate.vision' => [Group::MODERATOR_ID],
]);