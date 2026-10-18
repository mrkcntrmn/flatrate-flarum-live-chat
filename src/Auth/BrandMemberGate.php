<?php
/*
 * This file is part of flatrate/flarum-live-chat.
 */

namespace FlatRate\LiveChat\Auth;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Independent default-off member admission for the canonical Brand Live rooms.
 *
 * This is NOT a moderator permission, staff preview, or General Live rollout.
 * A disabled gate leaves historic Brand admin/beta behavior unchanged.
 */
class BrandMemberGate
{
    public const SETTING_KEY = 'flatrate-live-chat.brand_live_members_enabled';

    public function __construct(private ?SettingsRepositoryInterface $settings = null)
    {
    }

    public static function isEnabledValue($raw): bool
    {
        return $raw === '1' || $raw === 1 || $raw === true || $raw === 'true';
    }

    public function enabled(): bool
    {
        $raw = null;
        if ($this->settings !== null) {
            $raw = $this->settings->get(self::SETTING_KEY);
        } elseif (function_exists('resolve')) {
            try {
                $raw = resolve(SettingsRepositoryInterface::class)->get(self::SETTING_KEY);
            } catch (\Throwable $e) {
                $raw = null;
            }
        }
        return self::isEnabledValue($raw);
    }

    public function allowsActor(User $actor): bool
    {
        if (!$this->enabled() || !$actor->id) {
            return false;
        }
        if (!$actor->can(ChatAuthorization::PERM_ENABLED)) {
            return false;
        }
        if (method_exists($actor, 'isSuspended') && $actor->isSuspended()) {
            return false;
        }
        if (!empty($actor->suspended_until)) {
            $until = $actor->suspended_until;
            $timestamp = $until instanceof \DateTimeInterface
                ? $until->getTimestamp()
                : strtotime((string) $until);
            if ($timestamp && $timestamp > time()) {
                return false;
            }
        }
        return true;
    }
}
