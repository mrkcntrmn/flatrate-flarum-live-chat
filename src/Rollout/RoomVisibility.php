<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Rollout;

final class RoomVisibility
{
    public const VISIBLE = 'visible';
    public const HIDDEN = 'hidden';

    public static function isValid(string $value): bool
    {
        return $value === self::VISIBLE || $value === self::HIDDEN;
    }
}
