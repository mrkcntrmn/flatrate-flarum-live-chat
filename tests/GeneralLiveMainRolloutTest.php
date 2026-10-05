<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Auth\GeneralLiveGate;
use FlatRate\LiveChat\Auth\GeneralLiveMainRollout;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

class RolloutArraySettings implements SettingsRepositoryInterface
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
 * FORUM-LIVE-MAIN-001D: Admin Preview + User Live gates for pinned MAIN.
 * Rollout settings must not become General Live room authorization.
 */
class GeneralLiveMainRolloutTest extends TestCase
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
            $u->permissions[ChatAuthorization::PERM_CHAT] = true;
        }
        if ($id) {
            $u->permissions[ChatAuthorization::PERM_CHAT] = true;
        }
        return $u;
    }

    private function rollout(array $settings): GeneralLiveMainRollout
    {
        return new GeneralLiveMainRollout(new RolloutArraySettings($settings));
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

    public function testNewRolloutDefaultsAbsentAreFalseAndMasterAbsentStaysEnabled(): void
    {
        $this->assertFalse(GeneralLiveMainRollout::isOptInEnabled(null));
        $this->assertFalse(GeneralLiveMainRollout::isOptInEnabled(''));
        $this->assertFalse(GeneralLiveMainRollout::isOptInEnabled('0'));
        $this->assertFalse(GeneralLiveMainRollout::isOptInEnabled(false));
        $this->assertFalse(GeneralLiveMainRollout::isOptInEnabled('false'));
        $this->assertFalse(GeneralLiveMainRollout::isOptInEnabled('maybe'));
        $this->assertTrue(GeneralLiveMainRollout::isOptInEnabled('1'));
        $this->assertTrue(GeneralLiveMainRollout::isOptInEnabled(true));
        $this->assertTrue(GeneralLiveGate::isEnabled(null));
        $this->assertTrue(GeneralLiveGate::isEnabled(''));

        $gate = $this->rollout([]);
        $this->assertTrue($gate->masterEnabled());
        $this->assertFalse($gate->adminPreviewEnabled());
        $this->assertFalse($gate->userEnabled());
        $this->assertFalse($gate->availableTo($this->user(10)));
        $this->assertFalse($gate->availableTo($this->user(1, true)));
    }

    public function testGuestNeverReceivesMainLive(): void
    {
        foreach ([null, 0] as $id) {
            $guest = $this->user($id, false, false, true);
            $this->assertFalse($this->rollout([
                GeneralLiveGate::SETTING_KEY => '1',
                GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
                GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '1',
            ])->availableTo($guest));
        }
    }

    public function testMemberRequiresUserLive(): void
    {
        $member = $this->user(10);
        $off = $this->rollout([
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '0',
        ]);
        $this->assertFalse($off->availableTo($member));

        $on = $this->rollout([
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '1',
        ]);
        $this->assertTrue($on->availableTo($member));
    }

    public function testAdminPreviewIsAdminOnly(): void
    {
        $settings = [
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '0',
        ];
        $gate = $this->rollout($settings);
        $this->assertTrue($gate->availableTo($this->user(1, true)));
        $this->assertFalse($gate->availableTo($this->user(2, false)));
        $this->assertFalse($gate->availableTo($this->user(3, false, true)));
    }

    public function testAdminReceivesMainWhenUserLiveOnWithoutPreview(): void
    {
        $gate = $this->rollout([
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '0',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '1',
        ]);
        $this->assertTrue($gate->availableTo($this->user(1, true)));
        $this->assertTrue($gate->availableTo($this->user(3, false, true)));
        $this->assertTrue($gate->availableTo($this->user(10)));
    }

    public function testBothRolloutGatesOn(): void
    {
        $gate = $this->rollout([
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '1',
        ]);
        $this->assertTrue($gate->availableTo($this->user(1, true)));
        $this->assertTrue($gate->availableTo($this->user(10)));
        $this->assertFalse($gate->availableTo($this->user(null)));
    }

    public function testMasterOffOverridesEveryRolloutCombination(): void
    {
        $combos = [
            ['0', '0'],
            ['1', '0'],
            ['0', '1'],
            ['1', '1'],
        ];
        foreach ($combos as [$preview, $user]) {
            $gate = $this->rollout([
                GeneralLiveGate::SETTING_KEY => '0',
                GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => $preview,
                GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => $user,
            ]);
            $this->assertFalse($gate->masterEnabled());
            $this->assertFalse($gate->availableTo($this->user(1, true)));
            $this->assertFalse($gate->availableTo($this->user(10)));
            $this->assertFalse($gate->availableTo($this->user(3, false, true)));
            $this->assertFalse($gate->availableTo($this->user(null)));
        }
    }

    public function testRolloutOffPreservesExistingGeneralLiveRoomAuthorization(): void
    {
        $auth = new ChatAuthorization(new RolloutArraySettings([
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '0',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '0',
        ]));
        $member = $this->user(10);
        $this->assertTrue($auth->isGeneralLiveEnabled());
        $auth->assertCanReadRoom($member, $this->general());
        $auth->assertCanSubscribeRealtime($member, $this->general());
        $auth->assertCanPost($member, $this->general());
        $this->addToAssertionCount(1);

        $this->assertFalse($auth->isRoomVisibleToActor($member, $this->brand()));
        $mod = $this->user(3, false, true);
        $this->assertTrue($auth->canPreviewHiddenChatRooms($mod));
        $this->assertTrue($auth->isRoomVisibleToActor($mod, $this->brand()));
        $auth->assertCanReadRoom($mod, $this->brand());
    }

    public function testMasterOffStillDeniesGeneralLiveWhenRolloutFlagsAreOn(): void
    {
        $auth = new ChatAuthorization(new RolloutArraySettings([
            GeneralLiveGate::SETTING_KEY => '0',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '1',
        ]));
        $this->expectException(PermissionDeniedException::class);
        $auth->assertCanReadRoom($this->user(10), $this->general());
    }

    public function testChatViewPermissionStillRequired(): void
    {
        $gate = $this->rollout([
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '1',
        ]);
        $this->assertFalse($gate->availableTo($this->user(10, false, false, false)));
    }

    public function testSettingKeysAreDedicated(): void
    {
        $this->assertSame(
            'flatrate-live-chat.general_live_admin_preview_enabled',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY
        );
        $this->assertSame(
            'flatrate-live-chat.general_live_user_enabled',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY
        );
        $this->assertSame(
            'flatrate-live-chat.main_live_available',
            GeneralLiveMainRollout::FORUM_ATTRIBUTE
        );
        $this->assertNotSame(
            'flatrate-live-chat.live_chats_navigation_enabled',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY
        );
        $this->assertNotSame(
            'flatrate-live-chat.live_chats_navigation_enabled',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY
        );
        $this->assertNotSame(
            GeneralLiveGate::SETTING_KEY,
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY
        );
    }

    public function testAdminRegistrationLocaleAndForumAttribute(): void
    {
        $admin = file_get_contents(dirname(__DIR__) . '/js/src/admin/index.js');
        $locale = file_get_contents(dirname(__DIR__) . '/resources/locale/en.yaml');
        $extend = file_get_contents(dirname(__DIR__) . '/extend.php');
        $provider = file_get_contents(dirname(__DIR__) . '/js/src/forum/liveMainProvider.js');
        $auth = file_get_contents(dirname(__DIR__) . '/src/Auth/ChatAuthorization.php');
        $repo = file_get_contents(dirname(__DIR__) . '/src/ChatRepository.php');

        foreach ([
            'flatrate-live-chat.general_live_enabled',
            'flatrate-live-chat.general_live_admin_preview_enabled',
            'flatrate-live-chat.general_live_user_enabled',
        ] as $key) {
            $this->assertStringContainsString($key, $admin);
        }
        $this->assertStringContainsString('general_live_admin_preview_enabled:', $locale);
        $this->assertStringContainsString('general_live_user_enabled:', $locale);
        $this->assertStringContainsString('Admin Live Preview', $locale);
        $this->assertStringContainsString('User Live', $locale);
        $this->assertStringContainsString('GeneralLiveMainRollout::FORUM_ATTRIBUTE', $extend);
        $this->assertStringContainsString('GeneralLiveMainRollout', $extend);
        $rollout = file_get_contents(dirname(__DIR__) . '/src/Auth/GeneralLiveMainRollout.php');
        $this->assertStringContainsString(
            "public const FORUM_ATTRIBUTE = 'flatrate-live-chat.main_live_available'",
            $rollout
        );
        $this->assertStringContainsString(
            "->default('flatrate-live-chat.general_live_admin_preview_enabled', '0')",
            $extend
        );
        $this->assertStringContainsString(
            "->default('flatrate-live-chat.general_live_user_enabled', '0')",
            $extend
        );
        $this->assertStringNotContainsString(
            "->default('flatrate-live-chat.general_live_enabled', '0')",
            $extend
        );
        $this->assertStringNotContainsString(
            "serializeToForum('flatrate-live-chat.general_live_admin_preview_enabled'",
            $extend
        );
        $this->assertStringNotContainsString(
            "serializeToForum('flatrate-live-chat.general_live_user_enabled'",
            $extend
        );
        $this->assertStringContainsString('flatrate-live-chat.main_live_available', $provider);
        $this->assertStringNotContainsString('general_live_admin_preview_enabled', $provider);
        $this->assertStringNotContainsString('general_live_user_enabled', $provider);
        $this->assertStringNotContainsString('GeneralLiveMainRollout', $auth);
        $this->assertStringNotContainsString('general_live_admin_preview_enabled', $repo);
        $this->assertStringNotContainsString('general_live_user_enabled', $repo);
        $this->assertStringContainsString('default: false', $admin);
    }
}
