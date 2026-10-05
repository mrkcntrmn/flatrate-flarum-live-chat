<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Auth;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Actor-effective gate for Brand-board Live preview pins.
 *
 * Reuses the existing Admin Preview setting. It is not a second toggle and
 * it is not Brand room authorization. Moderators keep hidden-room
 * staff-preview through ChatAuthorization, but they are not admins, so this
 * presentation gate stays false for them.
 *
 * General Live master and User Live do not control Brand boards.
 */
class BrandLiveAdminPreview
{
    public const FORUM_ATTRIBUTE = 'flatrate-live-chat.brand_live_admin_preview_available';

    public function __construct(
        private ?SettingsRepositoryInterface $settings = null,
        private ?ChatAuthorization $authorization = null
    ) {
    }

    public function availableTo(User $actor): bool
    {
        if (!$actor->id) {
            return false;
        }
        if (!$actor->isAdmin()) {
            return false;
        }
        if (!$actor->can(ChatAuthorization::PERM_ENABLED)) {
            return false;
        }

        $rollout = new GeneralLiveMainRollout($this->settings);
        if (!$rollout->adminPreviewEnabled()) {
            return false;
        }

        $authorization = $this->authorization ?? new ChatAuthorization($this->settings);

        return $authorization->canPreviewHiddenChatRooms($actor);
    }
}
