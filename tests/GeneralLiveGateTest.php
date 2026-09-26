<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Auth\GeneralLiveGate;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

class ArraySettings implements SettingsRepositoryInterface
{
    public function __construct(private array $data = [])
    {
    }

    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }
}

/**
 * FORUM-LIVE-MAIN-001A: Admin General Live kill switch + migration-safe default.
 */
class GeneralLiveGateTest extends TestCase
{
    private function member(): User
    {
        $u = new User(10);
        $u->permissions = [
            ChatAuthorization::PERM_ENABLED => true,
            ChatAuthorization::PERM_CHAT => true,
        ];
        return $u;
    }

    private function general(): Chat
    {
        $chat = new Chat();
        $chat->type = 1;
        $chat->room_key = GeneralLiveGate::ROOM_KEY;
        $chat->visibility = RoomVisibility::VISIBLE;
        $chat->audience = RoomAudience::MEMBERS;
        return $chat;
    }

    private function brand(): Chat
    {
        $chat = new Chat();
        $chat->type = 1;
        $chat->room_key = 'toyota-live';
        $chat->visibility = RoomVisibility::HIDDEN;
        $chat->audience = RoomAudience::STAFF_PREVIEW;
        return $chat;
    }

    public function testMigrationAbsentMeansEnabled(): void
    {
        $this->assertTrue(GeneralLiveGate::isEnabled(null));
        $this->assertTrue(GeneralLiveGate::isEnabled(''));
        $auth = new ChatAuthorization(new ArraySettings([]));
        $this->assertTrue($auth->isGeneralLiveEnabled());
        $auth->assertCanReadRoom($this->member(), $this->general());
        $this->addToAssertionCount(1);
    }

    public function testExplicitOnAllowsGeneralLive(): void
    {
        $auth = new ChatAuthorization(new ArraySettings([
            GeneralLiveGate::SETTING_KEY => '1',
        ]));
        $this->assertTrue($auth->isGeneralLiveEnabled());
        $auth->assertCanSubscribeRealtime($this->member(), $this->general());
        $auth->assertCanPost($this->member(), $this->general());
        $this->addToAssertionCount(1);
    }

    public function testExplicitOffDeniesGeneralLiveReadSubscribePost(): void
    {
        $auth = new ChatAuthorization(new ArraySettings([
            GeneralLiveGate::SETTING_KEY => '0',
        ]));
        $this->assertFalse($auth->isGeneralLiveEnabled());
        $this->expectException(PermissionDeniedException::class);
        $auth->assertCanReadRoom($this->member(), $this->general());
    }

    public function testExplicitOffDeniesSubscribe(): void
    {
        $auth = new ChatAuthorization(new ArraySettings([
            GeneralLiveGate::SETTING_KEY => '0',
        ]));
        $this->expectException(PermissionDeniedException::class);
        $auth->assertCanSubscribeRealtime($this->member(), $this->general());
    }

    public function testExplicitOffDeniesPost(): void
    {
        $auth = new ChatAuthorization(new ArraySettings([
            GeneralLiveGate::SETTING_KEY => false,
        ]));
        $this->expectException(PermissionDeniedException::class);
        $auth->assertCanPost($this->member(), $this->general());
    }

    public function testBrandHiddenRoomUnchangedWhenGeneralLiveOff(): void
    {
        $auth = new ChatAuthorization(new ArraySettings([
            GeneralLiveGate::SETTING_KEY => '0',
        ]));
        $mod = $this->member();
        $mod->permissions[ChatAuthorization::PERM_MODERATE] = true;
        $this->assertTrue($auth->isRoomVisibleToActor($mod, $this->brand()));
        $auth->assertCanReadRoom($mod, $this->brand());
        $auth->assertCanSubscribeRealtime($mod, $this->brand());
        $this->addToAssertionCount(1);
    }

    public function testOrdinaryMemberStillCannotSeeBrandWhenGeneralLiveOff(): void
    {
        $auth = new ChatAuthorization(new ArraySettings([
            GeneralLiveGate::SETTING_KEY => '0',
        ]));
        $this->assertFalse($auth->isRoomVisibleToActor($this->member(), $this->brand()));
    }

    public function testSettingKeyIsDedicatedNotNavigationLegacy(): void
    {
        $this->assertSame('flatrate-live-chat.general_live_enabled', GeneralLiveGate::SETTING_KEY);
        $this->assertNotSame(
            'flatrate-live-chat.live_chats_navigation_enabled',
            GeneralLiveGate::SETTING_KEY
        );
    }

    public function testAdminSettingRegisteredInSource(): void
    {
        $admin = file_get_contents(dirname(__DIR__) . '/js/src/admin/index.js');
        $this->assertStringContainsString('flatrate-live-chat.general_live_enabled', $admin);
        $this->assertStringContainsString('general_live_enabled', $admin);
        $locale = file_get_contents(dirname(__DIR__) . '/resources/locale/en.yaml');
        $this->assertStringContainsString('general_live_enabled:', $locale);
        $extend = file_get_contents(dirname(__DIR__) . '/extend.php');
        $this->assertStringContainsString('general_live_enabled', $extend);
        // Must not default the kill switch to off on install.
        $this->assertStringNotContainsString(
            "->default('flatrate-live-chat.general_live_enabled', '0')",
            $extend
        );
    }

    public function testRepositoryExcludesGeneralWhenGateOff(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/ChatRepository.php');
        $this->assertStringContainsString('isGeneralLiveEnabled', $src);
        $this->assertStringContainsString('GeneralLiveGate::ROOM_KEY', $src);
    }

    public function testUnknownSettingValueFailsClosed(): void
    {
        $this->assertFalse(GeneralLiveGate::isEnabled('maybe'));
        $this->assertFalse(GeneralLiveGate::isEnabled(2));
    }
}
