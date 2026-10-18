<?php
/*
 * This file is part of flatrate/flarum-live-chat.
 */

namespace FlatRate\LiveChat\Auth;

use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Additional, narrowly scoped authorization for message moderation.
 *
 * The feature is strictly opt-in. It NEVER assigns the global moderator
 * permission or exposes staff-preview rooms. Missing/unresolvable projection
 * means denied, not an implicit role grant.
 *
 * Intended trusted integration (future independent source): the signed Forum
 * identity projection must implement isActiveForBrand(User, brandKey, capability).
 * A browser attribute, tag label, invitation token or chat subscription is
 * not a suitable permission authority.
 */
class BrandModeratorGate
{
    public const SETTING_KEY = 'flatrate-live-chat.brand_moderator_delegation_enabled';
    public const PROJECTION_CLASS = 'FlatRate\\SupabaseOAuth\\Brand\\BrandModeratorProjection';
    public const CAPABILITY_MODERATE_LIVE = 'moderate_live';

    public function __construct(
        private ?SettingsRepositoryInterface $settings = null,
        private ?RoomCatalog $catalog = null,
        private ?object $projection = null
    ) {
    }

    public static function isEnabledValue($value): bool
    {
        return $value === 1 || $value === '1' || $value === true || $value === 'true';
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
                return false;
            }
        }

        return self::isEnabledValue($raw);
    }

    public function allows(User $actor, Chat $chat): bool
    {
        if (!$this->enabled() || !$actor->id || (int) ($chat->type ?? 0) !== 1) {
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
            $ts = $until instanceof \DateTimeInterface
                ? $until->getTimestamp()
                : strtotime((string) $until);
            if ($ts && $ts > time()) {
                return false;
            }
        }

        $room = $this->catalog()->findByRoomKey((string) ($chat->room_key ?? ''));
        if (!is_array($room) || ($room['scopeType'] ?? null) !== 'board') {
            return false;
        }
        $brand = (string) ($room['scopeKey'] ?? '');
        if ($brand === ''
            || (string) ($chat->scope_type ?? '') !== 'board'
            || (string) ($chat->scope_key ?? '') !== $brand) {
            return false;
        }

        $projection = $this->projection();
        if (!$projection || !method_exists($projection, 'isActiveForBrand')) {
            return false;
        }

        try {
            // Only an explicitly active exact-brand grant may be considered.
            return $projection->isActiveForBrand(
                $actor, $brand, self::CAPABILITY_MODERATE_LIVE
            ) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function catalog(): RoomCatalog
    {
        if ($this->catalog === null) {
            $this->catalog = new RoomCatalog();
        }
        return $this->catalog;
    }

    private function projection(): ?object
    {
        if ($this->projection !== null) {
            return $this->projection;
        }
        // No runtime projection exists in this source tranche. Fail closed.
        if (!(class_exists(self::PROJECTION_CLASS) || interface_exists(self::PROJECTION_CLASS))
            || !function_exists('resolve')) {
            return null;
        }
        try {
            $candidate = resolve(self::PROJECTION_CLASS);
            return is_object($candidate) ? $candidate : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
