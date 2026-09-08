<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Rollout;

final class RoomAudience
{
    public const MEMBERS = 'members';
    public const STAFF_PREVIEW = 'staff-preview';

    public static function isValid(string $value): bool
    {
        return $value === self::MEMBERS || $value === self::STAFF_PREVIEW;
    }
}
