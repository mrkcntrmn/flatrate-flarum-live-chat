<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Api\Serializers;

class ChatUserSerializer extends ChatSerializer
{
    protected function getDefaultAttributes($chat)
    {
        $attributes = parent::getDefaultAttributes($chat);

        $chatUser = $chat->getChatUser($this->getActor());
        if ($chatUser) {
            $attributes['role'] = $chatUser->role;
            $attributes['joined_at'] = $this->formatDate($chatUser->joined_at);
            $attributes['readed_at'] = $this->formatDate($chatUser->readed_at);
            $attributes['removed_at'] = $chatUser->removed_at ? $this->formatDate($chatUser->removed_at) : null;
            $attributes['unreaded'] = $chat->unreadedCount($chatUser);
            // Do not expose removed_by internals unnecessarily.
        }

        return $attributes;
    }
}
