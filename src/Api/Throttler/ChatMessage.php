<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Api\Throttler;

use DateTime;
use Flarum\Settings\SettingsRepositoryInterface;
use FlatRate\LiveChat\Message;

/**
 * Floodgate bound to FlatRate chat message routes (not discussions/posts).
 */
class ChatMessage
{
    protected $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    public function __invoke($request): ?bool
    {
        $actor = $request->getAttribute('actor');
        $routeName = $request->getAttribute('routeName');
        $chatRoutes = [
            'neonchat.chatmessages.post',
            'flatrate-live-chat.chatmessages.post',
        ];

        // Flarum ThrottleApi: false overrides ALL throttlers; only return true/false
        // for chat post routes. Non-matching routes must be ignored (null).
        if (!in_array($routeName, $chatRoutes, true)) {
            return null;
        }

        if (!$actor || !$actor->id) {
            return null;
        }

        $number = (int) $this->settings->get('flatrate-live-chat.settings.floodgate.number');
        $time = $this->settings->get('flatrate-live-chat.settings.floodgate.time') ?: '10 seconds';

        if ($number <= 0) {
            return null;
        }

        $count = Message::where('created_at', '>=', new DateTime('-' . $time))
            ->where('user_id', $actor->id)
            ->count();

        // Throttle when the actor has already posted `number` messages in the window.
        return $count >= $number;
    }
}
