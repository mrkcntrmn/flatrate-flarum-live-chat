<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Api\Serializers;

use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Api\Serializer\BasicUserSerializer;
use Flarum\Settings\SettingsRepositoryInterface;

class MessageSerializer extends AbstractSerializer
{
    protected $type = 'chatmessages';

    protected $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    protected function getDefaultAttributes($message)
    {
        // Explicit allowlist — never dump model attributes; never expose IP fields.
        $attributes = [
            'message' => $message->message,
            'type' => (int) $message->type,
            'is_readed' => (bool) $message->is_readed,
            'created_at' => $this->formatDate($message->created_at),
            'edited_at' => $message->edited_at ? $this->formatDate($message->edited_at) : null,
            'chat_id' => (int) $message->chat_id,
        ];

        $actor = $this->getActor();
        if ($this->settings->get('flatrate-live-chat.settings.display.censor') && !$actor->id) {
            $attributes['message'] = str_repeat('*', strlen((string) $attributes['message']));
            $attributes['is_censored'] = true;
        }

        return $attributes;
    }

    public function user($message)
    {
        return $this->hasOne($message, BasicUserSerializer::class);
    }

    public function deleted_by($message)
    {
        return $this->hasOne($message, BasicUserSerializer::class);
    }

    public function chat($message)
    {
        return $this->hasOne($message, ChatSerializer::class);
    }
}
