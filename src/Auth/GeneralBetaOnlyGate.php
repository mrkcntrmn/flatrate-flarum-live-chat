<?php
/*
 * This file is part of flatrate/flarum-live-chat.
 */

namespace FlatRate\LiveChat\Auth;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Default-off, actor-effective General room audience.
 *
 * When disabled, legacy General access is unchanged. When enabled, only
 * unsuspended site admins or explicitly approved beta members are admitted.
 * GeneralLiveGate remains the independent operational kill switch.
 *
 * This gate must be checked by room lookup/list, read/post/realtime authorization,
 * and the MAIN rollout presentation. Never grant moderator/staff-preview
 * membership or infer approval from an ordinary Flarum group.
 */
class GeneralBetaOnlyGate
{
    public const SETTING_KEY = 'flatrate-live-chat.general_live_beta_only_enabled';

    public function __construct(
        private ?SettingsRepositoryInterface $settings = null,
        private ?MemberBetaGate $memberBeta = null
    ) {
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
        // Dark source installation must preserve existing General permissions.
        if (!$this->enabled()) {
            return true;
        }
        if (!$actor->id) {
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

        if ($actor->isAdmin()) {
            return true;
        }

        return $this->memberBeta()->allowsActor($actor);
    }

    private function memberBeta(): MemberBetaGate
    {
        if ($this->memberBeta === null) {
            $this->memberBeta = new MemberBetaGate($this->settings);
        }

        return $this->memberBeta;
    }
}
