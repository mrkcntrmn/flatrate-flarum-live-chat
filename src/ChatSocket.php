<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat;

use FlatRate\LiveChat\Realtime\CentrifugoChannelNamer;
use FlatRate\LiveChat\Realtime\EventEnvelope;
use FlatRate\LiveChat\Realtime\RealtimePublisher;

/**
 * Delegates to RealtimePublisher with per-room Centrifugo isolation.
 * SHARED_PUBLIC_REALTIME_CHANNEL=false — never publish to a shared public fanout.
 */
class ChatSocket
{
    public function __construct(
        private RealtimePublisher $publisher,
        private CentrifugoChannelNamer $channels
    ) {
    }

    public function sendChatEvent($chat_id, $event_id, $options)
    {
        $chat = Chat::find($chat_id);
        if (!$chat || !$chat->room_key) {
            return;
        }
        $channel = $this->channels->channelForRoom($chat);
        $type = EventEnvelope::mapLegacyEvent((string) $event_id) ?? (string) $event_id;
        $order = null;
        if (isset($options['message']['data']['id'])) {
            $order = (int) $options['message']['data']['id'];
        } elseif (isset($options['message']['id'])) {
            $order = (int) $options['message']['id'];
        }

        $this->publisher->publish($channel, $type, [
            'room_key' => $chat->room_key,
            'roomKey' => $chat->room_key,
            'chat_id' => $chat_id,
            'order' => $order,
            'message_id' => $order,
            'visibility' => $chat->visibility,
            'audience' => $chat->audience,
            'title' => $chat->title,
            // Keep legacy response for FakeRealtimePublisher HTTP matrix compatibility,
            // but EventEnvelope allowlist strips it before Centrifugo publish.
            'response' => $options,
        ]);
    }
}
