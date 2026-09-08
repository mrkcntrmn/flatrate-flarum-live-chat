<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'flatrate-live-chat.permissions.enabled' => Group::MEMBER_ID,
    'flatrate-live-chat.permissions.chat' => Group::MEMBER_ID,
    'flatrate-live-chat.permissions.moderate' => Group::MODERATOR_ID,
    'flatrate-live-chat.permissions.admin-rooms' => Group::ADMINISTRATOR_ID,
    // Explicitly do NOT grant create / create.channel to members.
]);
