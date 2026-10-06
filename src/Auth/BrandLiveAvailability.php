<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Auth;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Actor-effective Brand-board Live presentation.
 *
 * Two independent paths:
 *   admin + Admin Preview  -> BrandLiveAdminPreview
 *   approved member beta   -> MemberBetaGate via ChatAuthorization
 *
 * brand_live_admin_preview_available stays admin-preview-only.
 * Hidden-room staff preview does not imply this surface.
 * User Live is not an authorization source.
 * Missing canonical room rows stay owned by #1097; this gate does not probe them.
 */
class BrandLiveAvailability
{
    public const FORUM_ATTRIBUTE = 'flatrate-live-chat.brand_live_available';

    public function __construct(
        private ?SettingsRepositoryInterface $settings = null,
        private ?BrandLiveAdminPreview $adminPreview = null,
        private ?ChatAuthorization $authorization = null
    ) {
    }

    public function availableTo(User $actor): bool
    {
        if (!$actor->id) {
            return false;
        }
        if ($this->adminPreview()->availableTo($actor)) {
            return true;
        }

        return $this->memberBetaPresentation($actor);
    }

    private function memberBetaPresentation(User $actor): bool
    {
        if (!$actor->can(ChatAuthorization::PERM_ENABLED)) {
            return false;
        }

        return $this->authorization()->allowsApprovedMemberBeta($actor);
    }

    private function adminPreview(): BrandLiveAdminPreview
    {
        if ($this->adminPreview === null) {
            $this->adminPreview = new BrandLiveAdminPreview($this->settings);
        }

        return $this->adminPreview;
    }

    private function authorization(): ChatAuthorization
    {
        if ($this->authorization === null) {
            $this->authorization = new ChatAuthorization($this->settings);
        }

        return $this->authorization;
    }
}
