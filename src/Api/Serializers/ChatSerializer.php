<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Api\Serializers;

use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\Settings\SettingsRepositoryInterface;

class ChatSerializer extends AbstractSerializer
{
    protected $type = 'chats';

    protected $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    protected function getDefaultAttributes($chat)
    {
        // Explicit allowlist — durable FlatRate identity fields included.
        return [
            'title' => $chat->title,
            'color' => $chat->color,
            'icon' => $chat->icon,
            'type' => (int) $chat->type,
            'room_key' => $chat->room_key,
            'scope_type' => $chat->scope_type,
            'scope_key' => $chat->scope_key,
            'created_at' => $chat->created_at ? $this->formatDate($chat->created_at) : null,
        ];
    }

    protected function creator($chat)
    {
        return $this->hasOne($chat, UserSerializer::class);
    }

    protected function users($chat)
    {
        return $this->hasMany($chat, UserChatSerializer::class);
    }

    protected function messages($chat)
    {
        return $this->hasMany($chat, MessageSerializer::class);
    }

    protected function last_message($chat)
    {
        return $this->hasOne($chat, MessageSerializer::class);
    }

    protected function first_message($chat)
    {
        return $this->hasOne($chat, MessageSerializer::class);
    }
}
