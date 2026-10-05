<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\BrandLiveAdminPreview;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Auth\GeneralLiveGate;
use FlatRate\LiveChat\Auth\GeneralLiveMainRollout;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

class BrandLiveAdminPreviewTest extends TestCase
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
        return $u;
    }

    private function preview(array $settings): BrandLiveAdminPreview
    {
        $store = new RolloutArraySettings($settings);

        return new BrandLiveAdminPreview($store, new ChatAuthorization($store));
    }

    public function testAdminPreviewOnAdminIsTrue(): void
    {
        $gate = $this->preview([
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
        ]);
        $this->assertTrue($gate->availableTo($this->user(2, true)));
    }

    public function testAdminPreviewOffAdminIsFalse(): void
    {
        $gate = $this->preview([
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '0',
        ]);
        $this->assertFalse($gate->availableTo($this->user(2, true)));
    }

    public function testAdminPreviewOnModeratorIsFalseButHiddenPreviewRemains(): void
    {
        $store = new RolloutArraySettings([
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
        ]);
        $gate = new BrandLiveAdminPreview($store, new ChatAuthorization($store));
        $moderator = $this->user(3, false, true);
        $this->assertFalse($gate->availableTo($moderator));
        $this->assertTrue((new ChatAuthorization($store))->canPreviewHiddenChatRooms($moderator));
    }

    public function testAdminPreviewOnMemberIsFalse(): void
    {
        $gate = $this->preview([
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '1',
        ]);
        $this->assertFalse($gate->availableTo($this->user(10)));
    }

    public function testGuestIsFalse(): void
    {
        $gate = $this->preview([
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
        ]);
        $this->assertFalse($gate->availableTo($this->user(null)));
    }

    public function testGeneralMasterOffDoesNotDisableBrandPreview(): void
    {
        $gate = $this->preview([
            GeneralLiveGate::SETTING_KEY => '0',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
        ]);
        $this->assertTrue($gate->availableTo($this->user(2, true)));
    }

    public function testForumAttributeIsRegisteredWithoutANewSetting(): void
    {
        $extend = file_get_contents(dirname(__DIR__) . '/extend.php');
        $locale = file_get_contents(dirname(__DIR__) . '/resources/locale/en.yaml');
        $this->assertStringContainsString('BrandLiveAdminPreview::FORUM_ATTRIBUTE', $extend);
        $this->assertStringContainsString(
            "public const FORUM_ATTRIBUTE = 'flatrate-live-chat.brand_live_admin_preview_available'",
            file_get_contents(dirname(__DIR__) . '/src/Auth/BrandLiveAdminPreview.php')
        );
        $this->assertStringContainsString('Admin Live Preview', $locale);
        $this->assertStringContainsString(
            'Preview General Live on MAIN and Brand Live on Brand boards as an administrator before user rollout.',
            $locale
        );
        $this->assertStringNotContainsString('brand_live_admin_preview_enabled', $extend);
    }
}
