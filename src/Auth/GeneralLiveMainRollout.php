<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Auth;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Actor-effective gate for the pinned MAIN Live surface.
 *
 * This is not room authorization. The existing General Live room remains
 * governed only by GeneralLiveGate (master operational switch).
 *
 * Fail-closed rollout settings:
 *   absent / empty / 0 / unknown => disabled
 *   explicit 1/true             => enabled
 *
 * Effective availability:
 *   master enabled
 *   AND authenticated
 *   AND chat view permission (existing provider requirement)
 *   AND (user rollout OR (admin AND admin preview))
 *
 * Admin means User::isAdmin(). Moderators are not an Admin Preview audience.
 */
class GeneralLiveMainRollout
{
    public const ADMIN_PREVIEW_SETTING_KEY = 'flatrate-live-chat.general_live_admin_preview_enabled';
    public const USER_LIVE_SETTING_KEY = 'flatrate-live-chat.general_live_user_enabled';
    public const FORUM_ATTRIBUTE = 'flatrate-live-chat.main_live_available';

    public function __construct(private ?SettingsRepositoryInterface $settings = null)
    {
    }

    /**
     * @param mixed $raw
     */
    public static function isOptInEnabled($raw): bool
    {
        return $raw === 1 || $raw === '1' || $raw === true || $raw === 'true';
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function raw(string $key, $default = null)
    {
        if ($this->settings !== null) {
            return $this->settings->get($key, $default);
        }
        if (function_exists('resolve')) {
            try {
                return resolve(SettingsRepositoryInterface::class)->get($key, $default);
            } catch (\Throwable $e) {
                return $default;
            }
        }

        return $default;
    }

    public function masterEnabled(): bool
    {
        return GeneralLiveGate::isEnabled($this->raw(GeneralLiveGate::SETTING_KEY));
    }

    public function adminPreviewEnabled(): bool
    {
        return self::isOptInEnabled($this->raw(self::ADMIN_PREVIEW_SETTING_KEY));
    }

    public function userEnabled(): bool
    {
        return self::isOptInEnabled($this->raw(self::USER_LIVE_SETTING_KEY));
    }

    public function availableTo(User $actor): bool
    {
        if (!$this->masterEnabled()) {
            return false;
        }
        if (!$actor->id) {
            return false;
        }
        if (!$actor->can(ChatAuthorization::PERM_ENABLED)) {
            return false;
        }
        if ($this->userEnabled()) {
            return true;
        }
        if ($actor->isAdmin() && $this->adminPreviewEnabled()) {
            return true;
        }

        return false;
    }
}
