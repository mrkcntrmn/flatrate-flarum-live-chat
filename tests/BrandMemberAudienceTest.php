<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\BrandLiveAvailability;
use FlatRate\LiveChat\Auth\BrandMemberGate;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Auth\GeneralBetaOnlyGate;
use FlatRate\LiveChat\Auth\GeneralLiveGate;
use FlatRate\LiveChat\Auth\MemberBetaGate;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

class BrandMemberArraySettings implements SettingsRepositoryInterface
{
    public function __construct(private array $values = [])
    {
    }

    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }
}

class BrandMemberAudienceTest extends TestCase
{
    private function actor(?int $id = 10, bool $canChat = true): User
    {
        $user = new User($id);
        $user->permissions = [];
        if ($canChat) {
            $user->permissions[ChatAuthorization::PERM_ENABLED] = true;
            $user->permissions[ChatAuthorization::PERM_CHAT] = true;
        }
        return $user;
    }

    private function chat(string $roomKey, string $scopeType = 'board', string $scopeKey = 'ford'): Chat
    {
        $chat = new Chat();
        $chat->type = 1;
        $chat->room_key = $roomKey;
        $chat->scope_type = $scopeType;
        $chat->scope_key = $scopeKey;
        $chat->visibility = RoomVisibility::HIDDEN;
        $chat->audience = RoomAudience::STAFF_PREVIEW;
        return $chat;
    }

    private function settings(string $brandGate = '0'): BrandMemberArraySettings
    {
        return new BrandMemberArraySettings([
            BrandMemberGate::SETTING_KEY => $brandGate,
            MemberBetaGate::SETTING_KEY => '0',
            GeneralBetaOnlyGate::SETTING_KEY => '1',
        ]);
    }

    public function testDefaultOffGatePreservesHiddenBrandAndIsStrictlyOptIn(): void
    {
        foreach ([null, '', '0', false, 0, 'maybe', 2] as $raw) {
            $this->assertFalse(BrandMemberGate::isEnabledValue($raw));
        }
        foreach (['1', 1, true, 'true'] as $raw) {
            $this->assertTrue(BrandMemberGate::isEnabledValue($raw));
        }
        $settings = $this->settings();
        $gate = new BrandMemberGate($settings);
        $this->assertFalse($gate->allowsActor($this->actor()));
        $this->assertFalse((new ChatAuthorization($settings))->isRoomVisibleToActor(
            $this->actor(), $this->chat('ford-live')
        ));
        $this->assertFalse((new BrandLiveAvailability($settings))->availableTo($this->actor()));
        $extend = file_get_contents(dirname(__DIR__) . '/extend.php');
        $this->assertStringContainsString(
            "->default('flatrate-live-chat.brand_live_members_enabled', '0')",
            $extend
        );
    }

    public function testMemberCanReadPostAndSubscribeToExactFordBrandRoom(): void
    {
        $settings = $this->settings('1');
        $auth = new ChatAuthorization($settings);
        $room = $this->chat('ford-live');
        $this->assertTrue($auth->allowsBrandMemberRooms($this->actor()));
        $this->assertTrue($auth->isRoomVisibleToActor($this->actor(), $room));
        $auth->assertCanReadRoom($this->actor(), $room);
        $auth->assertCanReadMessages($this->actor(), $room);
        $auth->assertCanPost($this->actor(), $room);
        $auth->assertCanSubscribeRealtime($this->actor(), $room);
        $this->addToAssertionCount(4);
        $this->assertTrue((new BrandLiveAvailability($settings))->availableTo($this->actor()));

        // Explicit Brand member enrollment does not open beta-only General.
        $general = $this->chat(GeneralLiveGate::ROOM_KEY, 'navigation-group', 'community');
        $general->visibility = RoomVisibility::VISIBLE;
        $general->audience = RoomAudience::MEMBERS;
        $this->assertFalse($auth->isRoomVisibleToActor($this->actor(), $general));
    }

    public function testBrandRoomMustMatchExactCatalogIdentity(): void
    {
        $settings = $this->settings('1');
        $auth = new ChatAuthorization($settings);
        $catalog = new RoomCatalog();
        $this->assertCount(45, $catalog->canonicalBrandRoomKeys());
        $this->assertNotContains(GeneralLiveGate::ROOM_KEY, $catalog->canonicalBrandRoomKeys());
        foreach ([
            $this->chat('ford-live', 'board', 'chevrolet'),
            $this->chat('ford-live', 'navigation-group', 'ford'),
            $this->chat('invented-live', 'board', 'invented'),
            $this->chat('community-general-live', 'board', 'community'),
        ] as $room) {
            $this->assertFalse($auth->isRoomVisibleToActor($this->actor(), $room));
        }
    }

    public function testGuestAndMissingChatPermissionAreDenied(): void
    {
        $settings = $this->settings('1');
        $gate = new BrandMemberGate($settings);
        $this->assertFalse($gate->allowsActor($this->actor(null)));
        $this->assertFalse($gate->allowsActor($this->actor(10, false)));
        $this->assertFalse((new BrandLiveAvailability($settings))->availableTo($this->actor(10, false)));
    }

    public function testRepositoryFilterRequiresCatalogRoomAndScopePair(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/ChatRepository.php');
        $this->assertStringContainsString('allowsBrandMemberRooms($actor)', $src);
        $this->assertStringContainsString('canonicalBrandRoomScopes()', $src);
        $this->assertStringContainsString("->where('scope_key',", $src);
        $this->assertStringContainsString("->where('scope_type', 'board')", $src);
    }
}
