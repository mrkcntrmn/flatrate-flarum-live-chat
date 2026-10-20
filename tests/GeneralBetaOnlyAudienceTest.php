<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Auth\GeneralBetaOnlyGate;
use FlatRate\LiveChat\Auth\GeneralLiveGate;
use FlatRate\LiveChat\Auth\GeneralLiveMainRollout;
use FlatRate\LiveChat\Auth\MemberBetaGate;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

class BetaOnlyAudienceSettings implements SettingsRepositoryInterface
{
    public function __construct(private array $values = [])
    {
    }

    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }
}

class GeneralBetaOnlyAudienceTest extends TestCase
{
    private function actor(?int $id, bool $admin = false, bool $moderator = false): User
    {
        $actor = new User($id);
        $actor->isAdmin = $admin;
        $actor->permissions = [
            ChatAuthorization::PERM_ENABLED => true,
            ChatAuthorization::PERM_CHAT => true,
        ];
        if ($moderator) {
            $actor->permissions[ChatAuthorization::PERM_MODERATE] = true;
        }
        return $actor;
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
        $chat->room_key = 'ford-live';
        $chat->visibility = RoomVisibility::HIDDEN;
        $chat->audience = RoomAudience::STAFF_PREVIEW;
        return $chat;
    }

    private function beta(
        BetaOnlyAudienceSettings $settings,
        bool $approved = true
    ): MemberBetaGate {
        $projection = new class($approved) {
            public function __construct(private bool $approved)
            {
            }
            public function isActive(User $actor): bool
            {
                return $this->approved;
            }
        };
        return new MemberBetaGate($settings, $projection, true);
    }

    public function testGateIsStrictlyOptInAndDefaultOff(): void
    {
        foreach ([null, '', '0', 0, false, 'false', 'maybe', 2] as $raw) {
            $this->assertFalse(GeneralBetaOnlyGate::isEnabledValue($raw));
        }
        foreach (['1', 1, true, 'true'] as $raw) {
            $this->assertTrue(GeneralBetaOnlyGate::isEnabledValue($raw));
        }
        $settings = new BetaOnlyAudienceSettings([]);
        $gate = new GeneralBetaOnlyGate($settings, $this->beta($settings, false));
        $this->assertFalse($gate->enabled());
        $this->assertTrue($gate->allowsActor($this->actor(10)));

        $extension = file_get_contents(dirname(__DIR__) . '/extend.php');
        $this->assertStringContainsString(
            "->default('flatrate-live-chat.general_live_beta_only_enabled', '0')",
            $extension
        );
    }

    public function testGateOnDeniesOrdinaryAndGlobalModeratorEvenWithBroadUserSwitch(): void
    {
        $settings = new BetaOnlyAudienceSettings([
            GeneralBetaOnlyGate::SETTING_KEY => '1',
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '1',
            MemberBetaGate::SETTING_KEY => '1',
        ]);
        $beta = $this->beta($settings, false);
        $gate = new GeneralBetaOnlyGate($settings, $beta);
        $auth = new ChatAuthorization($settings, $beta, null, $gate);
        $rollout = new GeneralLiveMainRollout($settings, $beta, $gate);

        foreach ([$this->actor(10), $this->actor(11, false, true)] as $actor) {
            $this->assertFalse($auth->isRoomVisibleToActor($actor, $this->general()));
            $this->assertFalse($rollout->availableTo($actor));
            try {
                $auth->assertCanReadRoom($actor, $this->general());
                $this->fail('ordinary member bypassed General beta gate');
            } catch (PermissionDeniedException $expected) {
                $this->addToAssertionCount(1);
            }
            try {
                $auth->assertCanSubscribeRealtime($actor, $this->general());
                $this->fail('ordinary member subscribed to General realtime');
            } catch (PermissionDeniedException $expected) {
                $this->addToAssertionCount(1);
            }
            try {
                $auth->assertCanPost($actor, $this->general());
                $this->fail('ordinary member posted to General');
            } catch (PermissionDeniedException $expected) {
                $this->addToAssertionCount(1);
            }
        }

        // A global moderator keeps the pre-existing hidden Brand preview
        // but cannot enter beta-only General just because they moderate.
        $this->assertTrue($auth->isRoomVisibleToActor($this->actor(11, false, true), $this->brand()));
        $this->assertFalse($auth->isRoomVisibleToActor($this->actor(10), $this->brand()));
    }

    public function testApprovedBetaAndSiteAdminCanUseGeneral(): void
    {
        $settings = new BetaOnlyAudienceSettings([
            GeneralBetaOnlyGate::SETTING_KEY => '1',
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '0',
            MemberBetaGate::SETTING_KEY => '1',
        ]);
        $beta = $this->beta($settings, true);
        $gate = new GeneralBetaOnlyGate($settings, $beta);
        $auth = new ChatAuthorization($settings, $beta, null, $gate);
        $rollout = new GeneralLiveMainRollout($settings, $beta, $gate);

        foreach ([$this->actor(10), $this->actor(1, true)] as $actor) {
            $this->assertTrue($auth->isRoomVisibleToActor($actor, $this->general()));
            $this->assertTrue($rollout->availableTo($actor));
            $auth->assertCanReadRoom($actor, $this->general());
            $auth->assertCanSubscribeRealtime($actor, $this->general());
            $auth->assertCanPost($actor, $this->general());
            $this->addToAssertionCount(3);
        }

        // Missing/disabled approved cohort projection fails closed for members.
        $inactive = new MemberBetaGate($settings, null, true);
        $denied = new GeneralBetaOnlyGate($settings, $inactive);
        $this->assertFalse($denied->allowsActor($this->actor(10)));
        $this->assertTrue($denied->allowsActor($this->actor(1, true)));
    }

    public function testOperationalMasterStillOverridesBeta(): void
    {
        $settings = new BetaOnlyAudienceSettings([
            GeneralBetaOnlyGate::SETTING_KEY => '1',
            GeneralLiveGate::SETTING_KEY => '0',
            MemberBetaGate::SETTING_KEY => '1',
        ]);
        $beta = $this->beta($settings, true);
        $gate = new GeneralBetaOnlyGate($settings, $beta);
        $auth = new ChatAuthorization($settings, $beta, null, $gate);
        $this->assertTrue($gate->allowsActor($this->actor(10)));
        $this->expectException(PermissionDeniedException::class);
        $auth->assertCanReadRoom($this->actor(10), $this->general());
    }

    public function testGateOffPreservesExistingRoomBehavior(): void
    {
        $settings = new BetaOnlyAudienceSettings([
            GeneralBetaOnlyGate::SETTING_KEY => '0',
            GeneralLiveGate::SETTING_KEY => '1',
            MemberBetaGate::SETTING_KEY => '0',
        ]);
        $beta = $this->beta($settings, false);
        $gate = new GeneralBetaOnlyGate($settings, $beta);
        $auth = new ChatAuthorization($settings, $beta, null, $gate);
        $auth->assertCanReadRoom($this->actor(10), $this->general());
        $this->assertTrue($auth->isRoomVisibleToActor($this->actor(10), $this->general()));
    }

    public function testDirectoryQueryExcludesGeneralOnAudienceDenial(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/ChatRepository.php');
        $this->assertStringContainsString('!$this->auth->allowsGeneralRoomActor($actor)', $src);
        $this->assertStringContainsString('GeneralLiveGate::ROOM_KEY', $src);
    }
}
