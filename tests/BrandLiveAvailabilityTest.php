<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\BrandLiveAdminPreview;
use FlatRate\LiveChat\Auth\BrandLiveAvailability;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Auth\GeneralLiveGate;
use FlatRate\LiveChat\Auth\GeneralLiveMainRollout;
use FlatRate\LiveChat\Auth\MemberBetaGate;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

/**
 * FORUM-LIVE-BETA-005: actor-effective Brand-board Live pin.
 * Admin Preview and Member Beta remain independent.
 */
class BrandLiveAvailabilityTest extends TestCase
{
    private function user(?int $id, bool $admin = false, bool $moderator = false, bool $enabled = true): User
    {
        $u = new User($id);
        $u->isAdmin = $admin;
        $u->permissions = [];
        if ($enabled) {
            $u->permissions[ChatAuthorization::PERM_ENABLED] = true;
        }
        if ($moderator) {
            $u->permissions[ChatAuthorization::PERM_MODERATE] = true;
        }
        if ($id) {
            $u->permissions[ChatAuthorization::PERM_CHAT] = true;
        }

        return $u;
    }

    private function projection(bool $active): object
    {
        return new class($active) {
            public function __construct(private bool $active)
            {
            }

            public function isActive(User $actor): bool
            {
                return $this->active;
            }
        };
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function availability(array $settings, bool $betaActive): BrandLiveAvailability
    {
        $store = new RolloutArraySettings($settings);
        $gate = new MemberBetaGate($store, $this->projection($betaActive), true);
        $auth = new ChatAuthorization($store, $gate);
        $preview = new BrandLiveAdminPreview($store, $auth);

        return new BrandLiveAvailability($store, $preview, $auth);
    }

    private function hidden(string $roomKey): Chat
    {
        $chat = new Chat();
        $chat->type = 1;
        $chat->room_key = $roomKey;
        $chat->visibility = RoomVisibility::HIDDEN;
        $chat->audience = RoomAudience::STAFF_PREVIEW;

        return $chat;
    }

    public function testAdminPreviewOnIsAvailable(): void
    {
        $gate = $this->availability([
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
            MemberBetaGate::SETTING_KEY => '0',
        ], false);

        $this->assertTrue($gate->availableTo($this->user(2, true)));
    }

    public function testAdminPreviewOffIsNotReplacedByMemberBetaForOrdinaryAdmin(): void
    {
        $store = new RolloutArraySettings([
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '0',
            MemberBetaGate::SETTING_KEY => '1',
        ]);
        $auth = new ChatAuthorization($store, new MemberBetaGate($store, $this->projection(false), true));
        $preview = new BrandLiveAdminPreview($store, $auth);
        $gate = new BrandLiveAvailability($store, $preview, $auth);

        $this->assertFalse($preview->availableTo($this->user(2, true)));
        $this->assertFalse($gate->availableTo($this->user(2, true)));
    }

    public function testIndependentlyApprovedAdminStillUsesBetaPathWhenPreviewIsOff(): void
    {
        $gate = $this->availability([
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '0',
            MemberBetaGate::SETTING_KEY => '1',
        ], true);

        $this->assertTrue($gate->availableTo($this->user(2, true)));
    }

    public function testApprovedNonAdminBetaWithMemberBetaOnIsAvailable(): void
    {
        $gate = $this->availability([
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
            MemberBetaGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '0',
        ], true);
        $actor = $this->user(6);

        $this->assertFalse($actor->isAdmin());
        $this->assertTrue($gate->availableTo($actor));
    }

    public function testApprovedBetaWithMemberBetaOffIsUnavailable(): void
    {
        $gate = $this->availability([
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
            MemberBetaGate::SETTING_KEY => '0',
        ], true);

        $this->assertFalse($gate->availableTo($this->user(6)));
    }

    public function testOrdinaryMemberStaysUnavailableWhileMemberBetaIsOn(): void
    {
        $gate = $this->availability([
            MemberBetaGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '1',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
        ], false);

        $this->assertFalse($gate->availableTo($this->user(4)));
    }

    public function testGuestIsUnavailable(): void
    {
        $gate = $this->availability([
            MemberBetaGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
        ], true);

        $this->assertFalse($gate->availableTo($this->user(null)));
    }

    public function testNonBetaModeratorKeepsHiddenPreviewWithoutBrandPin(): void
    {
        $store = new RolloutArraySettings([
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
            MemberBetaGate::SETTING_KEY => '1',
        ]);
        $auth = new ChatAuthorization($store, new MemberBetaGate($store, $this->projection(false), true));
        $gate = new BrandLiveAvailability($store, new BrandLiveAdminPreview($store, $auth), $auth);
        $moderator = $this->user(3, false, true);

        $this->assertFalse($moderator->isAdmin());
        $this->assertTrue($auth->canPreviewHiddenChatRooms($moderator));
        $this->assertTrue($auth->isRoomVisibleToActor($moderator, $this->hidden('not-a-brand-live')));
        $this->assertFalse($gate->availableTo($moderator));
    }

    public function testBetaApprovedModeratorIsAvailableThroughBetaPath(): void
    {
        $store = new RolloutArraySettings([
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '0',
            MemberBetaGate::SETTING_KEY => '1',
        ]);
        $auth = new ChatAuthorization($store, new MemberBetaGate($store, $this->projection(true), true));
        $preview = new BrandLiveAdminPreview($store, $auth);
        $gate = new BrandLiveAvailability($store, $preview, $auth);
        $moderator = $this->user(3, false, true);

        $this->assertFalse($preview->availableTo($moderator));
        $this->assertTrue($auth->canPreviewHiddenChatRooms($moderator));
        $this->assertTrue($gate->availableTo($moderator));
    }

    public function testGeneralMainRegressionStaysOnItsOwnGate(): void
    {
        $store = new RolloutArraySettings([
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '0',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '0',
            MemberBetaGate::SETTING_KEY => '1',
        ]);
        $approved = new MemberBetaGate($store, $this->projection(true), true);
        $ordinary = new MemberBetaGate($store, $this->projection(false), true);

        $this->assertTrue((new GeneralLiveMainRollout($store, $approved))->availableTo($this->user(6)));
        $this->assertFalse((new GeneralLiveMainRollout($store, $ordinary))->availableTo($this->user(4)));
    }

    public function testExactBrandAuthorizationRegression(): void
    {
        $store = new RolloutArraySettings([
            MemberBetaGate::SETTING_KEY => '1',
        ]);
        $approved = new ChatAuthorization($store, new MemberBetaGate($store, $this->projection(true), true));
        $ordinary = new ChatAuthorization($store, new MemberBetaGate($store, $this->projection(false), true));
        $ford = $this->hidden('ford-live');
        $noncanonical = $this->hidden('not-a-brand-live');

        $this->assertTrue($approved->isRoomVisibleToActor($this->user(6), $ford));
        $this->assertFalse($ordinary->isRoomVisibleToActor($this->user(4), $ford));
        $this->assertFalse($approved->isRoomVisibleToActor($this->user(null), $ford));
        $this->assertFalse($approved->isRoomVisibleToActor($this->user(6), $noncanonical));
    }

    public function testForumAttributeIsSeparateFromAdminPreview(): void
    {
        $extend = file_get_contents(dirname(__DIR__) . '/extend.php');
        $source = file_get_contents(dirname(__DIR__) . '/src/Auth/BrandLiveAvailability.php');
        $provider = file_get_contents(dirname(__DIR__) . '/js/src/forum/liveBoardProvider.js');

        $this->assertStringContainsString('BrandLiveAvailability::FORUM_ATTRIBUTE', $extend);
        $this->assertStringContainsString('BrandLiveAdminPreview::FORUM_ATTRIBUTE', $extend);
        $this->assertStringContainsString(
            "public const FORUM_ATTRIBUTE = 'flatrate-live-chat.brand_live_available'",
            $source
        );
        $this->assertStringContainsString('flatrate-live-chat.brand_live_available', $provider);
        $this->assertStringNotContainsString('brand_live_admin_preview_available', $provider);
        $this->assertStringNotContainsString('brand_live_admin_preview_enabled', $extend);
        $this->assertStringContainsString('general_live_admin_preview_enabled', $extend);
    }
}
