<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat;

use FlatRate\LiveChat\Realtime\PerRoomChannelNamer;
use FlatRate\LiveChat\Realtime\RealtimePublisher;

/**
 * Legacy Neon socket replaced: no shared public Pusher channel.
 * Delegates to RealtimePublisher with per-room isolation.
 * REALTIME_IMPLEMENTATION_DECISION=PENDING — NullRealtimePublisher by default.
 */
class ChatSocket
{
    public function __construct(
        private RealtimePublisher $publisher,
        private PerRoomChannelNamer $channels
    ) {
    }

    public function sendChatEvent($chat_id, $event_id, $options)
    {
        $chat = Chat::find($chat_id);
        if (!$chat || !$chat->room_key) {
            return;
        }
        // Never sendPublic('public', ...) — SHARED_PUBLIC_PUSHER_CHANNEL=false
        $channel = $this->channels->channelKeyForRoom($chat);
        $this->publisher->publish($channel, (string) $event_id, [
            'chat_id' => $chat_id,
            'room_key' => $chat->room_key,
            'response' => $options,
        ]);
    }
}
