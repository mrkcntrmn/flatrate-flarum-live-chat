<?php

use Flarum\Database\Migration;

return Migration::addSettings([
    'flatrate-live-chat.settings.charlimit' => 512,
    'flatrate-live-chat.settings.floodgate.number' => 3,
    'flatrate-live-chat.settings.floodgate.time' => '1 hour'
]);