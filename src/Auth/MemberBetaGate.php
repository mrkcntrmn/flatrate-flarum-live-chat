<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Auth;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Default-off member beta gate.
 *
 * Fail closed: absent, empty, 0, false, and unknown values stay disabled.
 * Only explicit 1/true/'1'/'true' enables. That matches
 * GeneralLiveMainRollout::isOptInEnabled. It does not use GeneralLiveGate's
 * absent-means-enabled semantics.
 *
 * Cohort membership is the private Flarum projection
 * FlatRate\SupabaseOAuth\Beta\BetaTesterProjection::isActive(). This package
 * does not depend on that extension. A missing class, an unresolved container
 * binding, and an isActive() failure all mean not approved. Guests are not
 * approved. A missing projection row is already false inside that service.
 */
class MemberBetaGate
{
    public const SETTING_KEY = 'flatrate-live-chat.member_beta_enabled';
    public const PROJECTION_CLASS = 'FlatRate\\SupabaseOAuth\\Beta\\BetaTesterProjection';

    /**
     * @param object|null $projection explicit cohort service; null with $projectionProvided resolves softly
     * @param callable|null $resolver replaces container resolution when the projection was not provided
     */
    public function __construct(
        private ?SettingsRepositoryInterface $settings = null,
        private ?object $projection = null,
        private bool $projectionProvided = false,
        private $resolver = null
    ) {
    }

    /**
     * @param mixed $raw
     */
    public static function isEnabledValue($raw): bool
    {
        return GeneralLiveMainRollout::isOptInEnabled($raw);
    }

    public function enabled(): bool
    {
        return self::isEnabledValue($this->raw(self::SETTING_KEY));
    }

    public function isApproved(User $actor): bool
    {
        if (!$actor->id) {
            return false;
        }
        $projection = $this->resolveProjection();
        if (!is_object($projection) || !method_exists($projection, 'isActive')) {
            return false;
        }
        try {
            return $projection->isActive($actor) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function allowsActor(User $actor): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        return $this->isApproved($actor);
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

    private function resolveProjection(): ?object
    {
        if ($this->projectionProvided) {
            return $this->projection;
        }
        try {
            if ($this->resolver !== null) {
                $resolved = ($this->resolver)();
            } else {
                $resolved = $this->resolveFromContainer();
            }
        } catch (\Throwable $e) {
            return null;
        }

        return is_object($resolved) ? $resolved : null;
    }

    /**
     * @return object|null
     */
    private function resolveFromContainer()
    {
        if (!interface_exists(self::PROJECTION_CLASS) && !class_exists(self::PROJECTION_CLASS)) {
            return null;
        }
        if (!function_exists('resolve')) {
            return null;
        }
        $resolved = resolve(self::PROJECTION_CLASS);

        return is_object($resolved) ? $resolved : null;
    }
}
