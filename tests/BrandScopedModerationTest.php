<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\BrandModeratorGate;
use FlatRate\LiveChat\Auth\BrandMemberGate;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Auth\GeneralBetaOnlyGate;
use FlatRate\LiveChat\Auth\GeneralLiveGate;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

class BrandScopedModerationSettings implements SettingsRepositoryInterface
{
    public function __construct(private array $rows = [])
    {
    }
    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->rows) ? $this->rows[$key] : $default;
    }
}

class BrandScopedModerationTest extends TestCase
{
    private function member(?int $id = 10, bool $globalMod = false): User
    {
        $user = new User($id);
        $user->permissions = [ChatAuthorization::PERM_ENABLED => true, ChatAuthorization::PERM_CHAT => true];
        if ($globalMod) {
            $user->permissions[ChatAuthorization::PERM_MODERATE] = true;
        }
        return $user;
    }

    private function chat(string $room, string $type = 'board', string $key = 'ford'): Chat
    {
        $chat = new Chat();
        $chat->type = 1;
        $chat->room_key = $room;
        $chat->scope_type = $type;
        $chat->scope_key = $key;
        $chat->visibility = RoomVisibility::HIDDEN;
        $chat->audience = RoomAudience::STAFF_PREVIEW;
        return $chat;
    }

    private function settings(string $mod = '1'): BrandScopedModerationSettings
    {
        return new BrandScopedModerationSettings([
            BrandModeratorGate::SETTING_KEY => $mod,
            BrandMemberGate::SETTING_KEY => '1',
            GeneralBetaOnlyGate::SETTING_KEY => '1',
        ]);
    }

    private function projection(bool $active = true): object
    {
        return new class($active) {
            public function __construct(private bool $active)
            {
            }
            public function isActiveForBrand(User $actor, string $brand, string $action): bool
            {
                return $this->active
                    && $actor->id === 10
                    && $brand === 'ford'
                    && $action === BrandModeratorGate::CAPABILITY_MODERATE_LIVE;
            }
        };
    }

    public function testStrictDefaultOffAndNoProjectionFailClosed(): void
    {
        foreach ([null, '', '0', 0, false, 'false', 'maybe', 2] as $raw) {
            $this->assertFalse(BrandModeratorGate::isEnabledValue($raw));
        }
        foreach (['1', 1, true, 'true'] as $raw) {
            $this->assertTrue(BrandModeratorGate::isEnabledValue($raw));
        }

        $off = $this->settings('0');
        $this->assertFalse((new BrandModeratorGate($off, null, $this->projection()))->allows(
            $this->member(), $this->chat('ford-live')
        ));
        $this->assertFalse((new BrandModeratorGate($this->settings()))->allows(
            $this->member(), $this->chat('ford-live')
        ));
        $src = file_get_contents(dirname(__DIR__) . '/extend.php');
        $this->assertStringContainsString(
            "->default('flatrate-live-chat.brand_moderator_delegation_enabled', '0')",
            $src
        );
    }

    public function testExactFordGrantPermitsOnlyFordAndNotGlobalModeration(): void
    {
        $settings = $this->settings();
        $gate = new BrandModeratorGate($settings, null, $this->projection());
        $auth = new ChatAuthorization($settings, null, null, null, null, $gate);
        $ford = $this->chat('ford-live');
        $actor = $this->member();

        $this->assertTrue($gate->allows($actor, $ford));
        $this->assertFalse($auth->canPreviewHiddenChatRooms($actor)); // No staff-preview/global grant.
        $this->assertTrue($auth->isRoomVisibleToActor($actor, $ford)); // ordinary Brand member only.
        $this->assertTrue($auth->allowsScopedBrandModeration($actor, $ford));
        $auth->assertCanModerateMessage($actor, $ford);
        $this->addToAssertionCount(1);

        foreach ([
            $this->chat('chevrolet-live', 'board', 'chevrolet'),
            $this->chat('ford-live', 'board', 'chevrolet'),
            $this->chat('ford-live', 'navigation-group', 'ford'),
            $this->chat('not-canonical-live', 'board', 'ford'),
            $this->chat(GeneralLiveGate::ROOM_KEY, 'navigation-group', 'community'),
        ] as $invalid) {
            $this->assertFalse($gate->allows($actor, $invalid));
            try {
                $auth->assertCanModerateMessage($actor, $invalid);
                $this->fail('scoped moderator was allowed outside assigned Brand');
            } catch (PermissionDeniedException $expected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testProjectionDenialGuestAndExceptionFailClosed(): void
    {
        $settings = $this->settings();
        $brand = $this->chat('ford-live');
        $this->assertFalse((new BrandModeratorGate($settings, null, $this->projection(false)))
            ->allows($this->member(), $brand));
        $this->assertFalse((new BrandModeratorGate($settings, null, $this->projection()))
            ->allows($this->member(null), $brand));
        $this->assertFalse((new BrandModeratorGate($settings, null, $this->projection()))
            ->allows($this->member(11), $brand));
        $throwing = new class {
            public function isActiveForBrand(User $actor, string $brand, string $action): bool
            {
                throw new \RuntimeException('missing grant service');
            }
        };
        $this->assertFalse((new BrandModeratorGate($settings, null, $throwing))
            ->allows($this->member(), $brand));
    }

    public function testLegacyGlobalModerationRemainsSeparate(): void
    {
        $settings = $this->settings('0');
        $auth = new ChatAuthorization($settings, null, null, null, null,
            new BrandModeratorGate($settings, null, $this->projection()));
        $this->assertFalse($auth->allowsScopedBrandModeration($this->member(), $this->chat('ford-live')));
        $auth->assertCanModerateMessage($this->member(11, true), $this->chat('ford-live'));
        $this->addToAssertionCount(1);
    }

    public function testBothMessageModerationHandlersRequireTargetRoomChecks(): void
    {
        $root = dirname(__DIR__);
        $delete = file_get_contents($root . '/src/Commands/DeleteMessageHandler.php');
        $edit = file_get_contents($root . '/src/Commands/EditMessageHandler.php');
        $this->assertStringContainsString('assertCanModerateMessage($actor, $chat)', $delete);
        $this->assertStringContainsString('allowsScopedBrandModeration($actor, $chat)', $edit);
        $this->assertStringContainsString('$actor->assertPermission($actor->id == $message->user_id)', $edit);
        $this->assertStringContainsString('($message->deleted_by ?? 0)', $edit);
    }
}
