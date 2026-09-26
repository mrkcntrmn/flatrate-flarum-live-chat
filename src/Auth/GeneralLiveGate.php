<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Auth;

use FlatRate\LiveChat\Chat;

/**
 * Operational Admin kill switch for community-general-live.
 *
 * Migration-safe semantics (no schema migration):
 *   setting absent / null / ''  -> enabled (preserve current production)
 *   setting explicitly 0/false  -> disabled
 *   setting explicitly 1/true   -> enabled
 *
 * Do not reuse flatrate-live-chat.live_chats_navigation_enabled.
 */
class GeneralLiveGate
{
    public const SETTING_KEY = 'flatrate-live-chat.general_live_enabled';
    public const ROOM_KEY = 'community-general-live';

    public static function isGeneralLiveRoom(?Chat $chat): bool
    {
        if (!$chat) {
            return false;
        }
        return (string) ($chat->room_key ?? '') === self::ROOM_KEY;
    }

    public static function isGeneralLiveRoomKey(?string $roomKey): bool
    {
        return (string) $roomKey === self::ROOM_KEY;
    }

    /**
     * @param mixed $raw Value from SettingsRepositoryInterface::get()
     */
    public static function isEnabled($raw): bool
    {
        // Absent / empty: preserve current enabled behavior after package install.
        if ($raw === null || $raw === '') {
            return true;
        }

        if ($raw === 0 || $raw === '0' || $raw === false || $raw === 'false') {
            return false;
        }

        if ($raw === 1 || $raw === '1' || $raw === true || $raw === 'true') {
            return true;
        }

        // Unknown explicit values fail closed.
        return false;
    }
}
