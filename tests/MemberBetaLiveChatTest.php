<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Auth\GeneralLiveGate;
use FlatRate\LiveChat\Auth\GeneralLiveMainRollout;
use FlatRate\LiveChat\Auth\MemberBetaGate;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\Rollout\RolloutProfile;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\TestCase;

class MemberBetaArraySettings implements SettingsRepositoryInterface
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
 * FORUM-LIVE-BETA-001C: default-off member beta for General MAIN and exact Brand rooms.
 */
class MemberBetaLiveChatTest extends TestCase
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

    private function settings(array $data): MemberBetaArraySettings
    {
        return new MemberBetaArraySettings($data);
    }

    private function activeProjection(): object
    {
        return new class {
            public function isActive(User $actor): bool
            {
                return true;
            }
        };
    }

    private function inactiveProjection(): object
    {
        return new class {
            public function isActive(User $actor): bool
            {
                return false;
            }
        };
    }

    private function throwingProjection(): object
    {
        return new class {
            public function isActive(User $actor): bool
            {
                throw new \RuntimeException('projection failed');
            }
        };
    }

    private function gate(
        MemberBetaArraySettings $settings,
        ?object $projection = null,
        bool $projectionProvided = false,
        $resolver = null
    ): MemberBetaGate {
        if ($projection !== null) {
            $projectionProvided = true;
        }

        return new MemberBetaGate($settings, $projection, $projectionProvided, $resolver);
    }

    private function rollout(MemberBetaArraySettings $settings, MemberBetaGate $gate): GeneralLiveMainRollout
    {
        return new GeneralLiveMainRollout($settings, $gate);
    }

    private function auth(MemberBetaArraySettings $settings, MemberBetaGate $gate): ChatAuthorization
    {
        return new ChatAuthorization($settings, $gate);
    }

    private function hidden(string $roomKey): Chat
    {
        $chat = new Chat();
        $chat->type = 1;
        $chat->room_key = $roomKey;
        $chat->visibility = RoomVisibility::HIDDEN;
        $chat->audience = RoomAudience::STAFF_PREVIEW;
        $chat->title = $roomKey;

        return $chat;
    }

    private function baseSettings(string $memberBeta = '0'): array
    {
        return [
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '0',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '0',
            MemberBetaGate::SETTING_KEY => $memberBeta,
        ];
    }

    public function testMemberBetaSettingDefaultsOffAndFailsClosed(): void
    {
        $extend = file_get_contents(dirname(__DIR__) . '/extend.php');
        $this->assertStringContainsString(
            "->default('flatrate-live-chat.member_beta_enabled', '0')",
            $extend
        );
        $this->assertStringContainsString(
            "->default('flatrate-live-chat.general_live_user_enabled', '0')",
            $extend
        );
        $this->assertSame('flatrate-live-chat.member_beta_enabled', MemberBetaGate::SETTING_KEY);

        $disabled = [null, '', '0', 0, false, 'false', 'maybe', 'yes', 2, 'on'];
        foreach ($disabled as $raw) {
            $this->assertFalse(MemberBetaGate::isEnabledValue($raw), 'raw ' . var_export($raw, true));
            $this->assertFalse(GeneralLiveMainRollout::isOptInEnabled($raw));
        }
        foreach (['1', 1, true, 'true'] as $raw) {
            $this->assertTrue(MemberBetaGate::isEnabledValue($raw), 'raw ' . var_export($raw, true));
        }

        $absent = $this->gate($this->settings([]));
        $this->assertFalse($absent->enabled());
        $explicitOff = $this->gate($this->settings([MemberBetaGate::SETTING_KEY => '0']));
        $this->assertFalse($explicitOff->enabled());
        $unknown = $this->gate($this->settings([MemberBetaGate::SETTING_KEY => 'maybe']));
        $this->assertFalse($unknown->enabled());
        $on = $this->gate($this->settings([MemberBetaGate::SETTING_KEY => '1']));
        $this->assertTrue($on->enabled());

        $this->assertTrue(GeneralLiveGate::isEnabled(null));
        $this->assertFalse(MemberBetaGate::isEnabledValue(null));

        $admin = file_get_contents(dirname(__DIR__) . '/js/src/admin/index.js');
        $locale = file_get_contents(dirname(__DIR__) . '/resources/locale/en.yaml');
        $this->assertStringContainsString('flatrate-live-chat.member_beta_enabled', $admin);
        $this->assertStringContainsString('member_beta_enabled:', $locale);
        $this->assertStringContainsString('default: false', $admin);
    }

    public function testProjectionMissingUnresolvedOrThrowingIsNotApproved(): void
    {
        $member = $this->user(10);
        $guest = $this->user(null);
        $settings = $this->settings([MemberBetaGate::SETTING_KEY => '1']);

        $this->assertFalse(
            interface_exists(MemberBetaGate::PROJECTION_CLASS) || class_exists(MemberBetaGate::PROJECTION_CLASS)
        );
        $missing = new MemberBetaGate($settings);
        $this->assertFalse($missing->isApproved($member));
        $this->assertFalse($missing->allowsActor($member));

        $unresolved = new MemberBetaGate($settings, null, true);
        $this->assertFalse($unresolved->isApproved($member));

        $container = new MemberBetaGate($settings, null, false, function () {
            throw new \RuntimeException('container cannot resolve projection');
        });
        $this->assertFalse($container->isApproved($member));

        $throwing = $this->gate($settings, $this->throwingProjection());
        $this->assertFalse($throwing->isApproved($member));
        $this->assertFalse($throwing->allowsActor($member));

        $inactive = $this->gate($settings, $this->inactiveProjection());
        $this->assertFalse($inactive->isApproved($member));

        $active = $this->gate($settings, $this->activeProjection());
        $this->assertFalse($active->isApproved($guest));
        $this->assertTrue($active->isApproved($member));
        $this->assertFalse($active->allowsActor($guest));
    }

    public function testApprovedBetaWithGateOnMakesGeneralMainAvailable(): void
    {
        $settings = $this->settings($this->baseSettings('1'));
        $gate = $this->gate($settings, $this->activeProjection());
        $rollout = $this->rollout($settings, $gate);
        $member = $this->user(10);

        $this->assertFalse($rollout->userEnabled());
        $this->assertFalse($rollout->adminPreviewEnabled());
        $this->assertTrue($rollout->availableTo($member));
        $this->assertFalse($rollout->availableTo($this->user(10, false, false, false)));
        $this->assertFalse($rollout->availableTo($this->user(null)));
    }

    public function testGateOffLeavesGeneralMainUnchanged(): void
    {
        $settings = $this->settings([
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '0',
            MemberBetaGate::SETTING_KEY => '0',
        ]);
        $gate = $this->gate($settings, $this->activeProjection());
        $rollout = $this->rollout($settings, $gate);

        $this->assertFalse($rollout->availableTo($this->user(10)));
        $this->assertTrue($rollout->availableTo($this->user(1, true)));
        $this->assertFalse($rollout->availableTo($this->user(3, false, true)));

        $auth = $this->auth($settings, $gate);
        $this->assertFalse($auth->isRoomVisibleToActor($this->user(10), $this->hidden('toyota-live')));
        $this->assertTrue($auth->canPreviewHiddenChatRooms($this->user(3, false, true)));
        $this->assertTrue($auth->isRoomVisibleToActor($this->user(3, false, true), $this->hidden('toyota-live')));
    }

    public function testMasterOffStillBlocksApprovedBeta(): void
    {
        $settings = $this->settings([
            GeneralLiveGate::SETTING_KEY => '0',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '0',
            MemberBetaGate::SETTING_KEY => '1',
        ]);
        $gate = $this->gate($settings, $this->activeProjection());
        $this->assertFalse($this->rollout($settings, $gate)->availableTo($this->user(10)));
    }

    public function testCatalogBrandRoomsStayHiddenAndCountFortyFive(): void
    {
        $catalog = new RoomCatalog();
        $keys = $catalog->canonicalBrandRoomKeys();
        $this->assertCount(45, $keys);
        $this->assertSame(45, $catalog->brandRoomCount());
        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->assertNotContains('community-general-live', $keys);
        $this->assertFalse($catalog->isCanonicalBrandRoomKey('community-general-live'));
        $this->assertTrue($catalog->isCanonicalBrandRoomKey('toyota-live'));
        $this->assertFalse($catalog->isCanonicalBrandRoomKey('not-a-catalog-room'));

        $profile = new RolloutProfile();
        foreach ($keys as $key) {
            $policy = $profile->policyForRoomKey($key);
            $this->assertSame(RoomVisibility::HIDDEN, $policy['visibility'], $key);
            $this->assertSame(RoomAudience::STAFF_PREVIEW, $policy['audience'], $key);
        }
        $general = $profile->policyForRoomKey('community-general-live');
        $this->assertSame(RoomVisibility::VISIBLE, $general['visibility']);
        $this->assertSame(RoomAudience::MEMBERS, $general['audience']);
    }

    public function testApprovedBetaSeesExactBrandRoomsButNotTheVisibleList(): void
    {
        $settings = $this->settings($this->baseSettings('1'));
        $gate = $this->gate($settings, $this->activeProjection());
        $auth = $this->auth($settings, $gate);
        $member = $this->user(10);
        $catalog = new RoomCatalog();
        $repo = new ChatRepository($auth);

        $this->assertFalse($auth->canPreviewHiddenChatRooms($member));
        $this->assertTrue($auth->allowsApprovedMemberBeta($member));

        foreach ($catalog->canonicalBrandRoomKeys() as $key) {
            $room = $this->hidden($key);
            $this->assertTrue($auth->isRoomVisibleToActor($member, $room), $key);
            $this->assertSame(RoomVisibility::HIDDEN, $room->visibility);
            $this->assertSame(RoomAudience::STAFF_PREVIEW, $room->audience);
            $this->assertFalse($repo->hiddenSubscriptionEligible($member, $room, false), $key);
        }

        $secret = $this->hidden('not-a-catalog-room');
        $this->assertFalse($auth->isRoomVisibleToActor($member, $secret));
        $this->assertFalse($repo->isExactAuthorizedHiddenBrandRoom($member, $secret));

        $src = file_get_contents(dirname(__DIR__) . '/src/ChatRepository.php');
        $this->assertMatchesRegularExpression(
            '/function queryVisible\(User \$actor\).*?canPreviewHiddenChatRooms\(\$actor\).*?RoomVisibility::VISIBLE.*?RoomAudience::MEMBERS/s',
            $src
        );
        if (preg_match('/function queryVisible\(User \$actor\)\s*\{(.*?)\n    public function findOrFail/s', $src, $match)) {
            $this->assertStringNotContainsString('MemberBeta', $match[1]);
            $this->assertStringNotContainsString('member_beta', $match[1]);
            $this->assertStringNotContainsString('allowsApprovedMemberBeta', $match[1]);
        } else {
            $this->fail('queryVisible body was not isolated');
        }
        $list = file_get_contents(dirname(__DIR__) . '/src/Api/Controllers/ListChatsController.php');
        $this->assertStringContainsString('queryVisible($actor)', $list);
        $this->assertStringNotContainsString('canonicalBrandRoomKeys', $list);
    }

    public function testExactRoomKeyRetrievalAllowsOneBrandRoomAndHidesTheRest(): void
    {
        $settings = $this->settings($this->baseSettings('1'));
        $gate = $this->gate($settings, $this->activeProjection());
        $auth = $this->auth($settings, $gate);
        $member = $this->user(10);
        $toyota = $this->hidden('toyota-live');
        $toyota->id = 45;
        $secret = $this->hidden('not-a-catalog-room');
        $secret->id = 99;

        $repo = $this->retrievalRepo($auth, [
            'toyota-live' => $toyota,
            'not-a-catalog-room' => $secret,
        ], [
            '45' => $toyota,
            '99' => $secret,
        ]);

        $found = $repo->findByRoomKeyOrFail('toyota-live', $member);
        $this->assertSame('toyota-live', $found->room_key);
        $byId = $repo->findOrFail(45, $member);
        $this->assertSame(45, (int) $byId->id);

        try {
            $repo->findByRoomKeyOrFail('not-a-catalog-room', $member);
            $this->fail('non-catalog hidden room must 404');
        } catch (ModelNotFoundException $e) {
            $this->assertNotInstanceOf(PermissionDeniedException::class, $e);
        }

        $deniedSettings = $this->settings($this->baseSettings('0'));
        $deniedGate = $this->gate($deniedSettings, $this->activeProjection());
        $deniedAuth = $this->auth($deniedSettings, $deniedGate);
        $deniedRepo = $this->retrievalRepo($deniedAuth, ['toyota-live' => $toyota], ['45' => $toyota]);
        try {
            $deniedRepo->findByRoomKeyOrFail('toyota-live', $member);
            $this->fail('non-beta member must 404 on a brand room');
        } catch (ModelNotFoundException $e) {
            $this->assertNotInstanceOf(PermissionDeniedException::class, $e);
        }

        $unapproved = $this->gate($settings, $this->inactiveProjection());
        $unapprovedRepo = $this->retrievalRepo($this->auth($settings, $unapproved), ['toyota-live' => $toyota]);
        $this->expectException(ModelNotFoundException::class);
        $unapprovedRepo->findByRoomKeyOrFail('toyota-live', $member);
    }

    public function testDirectoryAddsSubscribedBrandRoomsOnly(): void
    {
        $settings = $this->settings($this->baseSettings('1'));
        $gate = $this->gate($settings, $this->activeProjection());
        $auth = $this->auth($settings, $gate);
        $member = $this->user(10);
        $repo = new ChatRepository($auth);
        $catalog = new RoomCatalog();

        foreach ($catalog->canonicalBrandRoomKeys() as $key) {
            $this->assertFalse($repo->hiddenSubscriptionEligible($member, $this->hidden($key), false));
        }
        $this->assertTrue($repo->hiddenSubscriptionEligible($member, $this->hidden('toyota-live'), true));
        $this->assertFalse($repo->hiddenSubscriptionEligible($member, $this->hidden('not-a-catalog-room'), true));

        $moderator = $this->user(3, false, true);
        $this->assertFalse($repo->hiddenSubscriptionEligible($moderator, $this->hidden('toyota-live'), true));
        $this->assertTrue($auth->canPreviewHiddenChatRooms($moderator));
        $this->assertTrue($auth->isRoomVisibleToActor($moderator, $this->hidden('not-a-catalog-room')));

        $src = file_get_contents(dirname(__DIR__) . '/src/ChatRepository.php');
        $this->assertStringNotContainsString('RoomCatalog', $src);
        $this->assertStringNotContainsString('canonicalBrandRoomKeys', $src);
        $this->assertStringContainsString("whereNull('neonchat_chat_user.removed_at')", $src);
        $this->assertStringContainsString('allowsApprovedMemberBeta', $src);
        $this->assertStringContainsString('findByRoomKeyOrFail', file_get_contents(
            dirname(__DIR__) . '/src/Api/Controllers/SubscribeRoomController.php'
        ));
        $this->assertStringContainsString('findByRoomKeyOrFail', file_get_contents(
            dirname(__DIR__) . '/src/Api/Controllers/RealtimeSubscriptionTokenController.php'
        ));
    }

    public function testModeratorStaffPreviewStaysIndependentOfBeta(): void
    {
        $settings = $this->settings([
            GeneralLiveGate::SETTING_KEY => '1',
            GeneralLiveMainRollout::ADMIN_PREVIEW_SETTING_KEY => '1',
            GeneralLiveMainRollout::USER_LIVE_SETTING_KEY => '0',
            MemberBetaGate::SETTING_KEY => '0',
        ]);
        $gate = $this->gate($settings, $this->inactiveProjection());
        $auth = $this->auth($settings, $gate);
        $rollout = $this->rollout($settings, $gate);
        $moderator = $this->user(3, false, true);

        $this->assertTrue($auth->canPreviewHiddenChatRooms($moderator));
        $this->assertFalse($moderator->isAdmin());
        $this->assertFalse($rollout->availableTo($moderator));
        $this->assertTrue($rollout->availableTo($this->user(1, true)));
        $this->assertTrue($auth->isRoomVisibleToActor($moderator, $this->hidden('not-a-catalog-room')));
        $this->assertTrue($auth->isRoomVisibleToActor($this->user(1, true), $this->hidden('not-a-catalog-room')));
        $this->assertFalse($auth->allowsApprovedMemberBeta($moderator));
    }

    public function testNoOauthComposerDependencyOrForumBetaAttributes(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
        $this->assertIsArray($composer);
        $this->assertArrayNotHasKey('flatrate/wiki-supabase-oauth', $composer['require'] ?? []);
        $this->assertArrayNotHasKey('flatrate/wiki-supabase-oauth', $composer['require-dev'] ?? []);
        $this->assertStringNotContainsString('supabase-oauth', (string) file_get_contents(dirname(__DIR__) . '/composer.json'));

        $extend = (string) file_get_contents(dirname(__DIR__) . '/extend.php');
        $start = strpos($extend, 'Extend\\ApiSerializer(ForumSerializer::class)');
        $end = strpos($extend, 'Extend\\ThrottleApi');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $serializer = substr($extend, $start, $end - $start);
        $this->assertStringNotContainsString('member_beta_enabled', $serializer);
        $this->assertStringNotContainsString('flatrate_beta_tester_access', $serializer);
        $this->assertStringNotContainsString('beta_tester_active', $serializer);
        $this->assertStringNotContainsString(
            "serializeToForum('flatrate-live-chat.member_beta_enabled'",
            $extend
        );
        $this->assertStringNotContainsString('flatrate_beta_tester_access', $extend);
        $this->assertStringNotContainsString('beta_tester_active', $extend);

        $forumRoot = dirname(__DIR__) . '/js/src/forum';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($forumRoot));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('member_beta_enabled', $text, $file->getPathname());
            $this->assertStringNotContainsString('flatrate_beta_tester_access', $text, $file->getPathname());
            $this->assertStringNotContainsString('beta_tester_active', $text, $file->getPathname());
        }

        $dist = dirname(__DIR__) . '/js/dist/forum.js';
        if (is_file($dist)) {
            $forumDist = (string) file_get_contents($dist);
            $this->assertStringNotContainsString('member_beta_enabled', $forumDist);
            $this->assertStringNotContainsString('flatrate_beta_tester_access', $forumDist);
            $this->assertStringNotContainsString('beta_tester_active', $forumDist);
        }
    }

    private function retrievalRepo(ChatAuthorization $auth, array $byKey, array $byId = []): ChatRepository
    {
        return new class($auth, $byKey, $byId) extends ChatRepository {
            public function __construct(
                ChatAuthorization $auth,
                private array $byKey,
                private array $byId
            ) {
                parent::__construct($auth);
            }

            protected function listedRoomByKey(User $actor, string $roomKey): ?Chat
            {
                return null;
            }

            protected function listedRoomById(User $actor, $id): ?Chat
            {
                return null;
            }

            protected function storedRoomByKey(string $roomKey): ?Chat
            {
                return $this->byKey[$roomKey] ?? null;
            }

            protected function storedRoomById($id): ?Chat
            {
                return $this->byId[(string) $id] ?? null;
            }
        };
    }
}
