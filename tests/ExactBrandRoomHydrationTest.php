<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Api\Controllers\ShowLiveRoomController;
use FlatRate\LiveChat\Api\ExactRoomLookup;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Auth\MemberBetaGate;
use FlatRate\LiveChat\Catalog\RoomCatalog;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\TestCase;
use Tobscure\JsonApi\Document;

class ExactRoomArraySettings implements SettingsRepositoryInterface
{
    public function __construct(private array $data = [])
    {
    }

    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }
}

class ExactBrandRoomHydrationTest extends TestCase
{
    public function testApprovedBetaExactCanonicalRoomsAllowWithoutSubscribing(): void
    {
        $lookup = $this->lookup('1', true);
        foreach (['ford-live', 'toyota-live', 'alfa-romeo-live', 'genesis-live', 'gm-live', 'cdjr-live'] as $key) {
            $chat = $lookup->find($this->member(), $key);
            $this->assertSame($key, $chat->room_key);
            $this->assertSame(0, $chat->subscribes, $key);
        }
    }

    public function testApprovedBetaNoncanonicalAndOrdinaryAndUnapprovedDeny(): void
    {
        $lookup = $this->lookup('1', true);
        try {
            $lookup->find($this->member(), 'not-a-brand-live');
            $this->fail('noncanonical hidden room must 404');
        } catch (ModelNotFoundException $e) {
            $this->assertNotInstanceOf(PermissionDeniedException::class, $e);
        }

        $off = $this->lookup('0', true);
        try {
            $off->find($this->member(), 'ford-live');
            $this->fail('member beta off must 404');
        } catch (ModelNotFoundException $e) {
            $this->assertNotInstanceOf(PermissionDeniedException::class, $e);
        }

        $unapproved = $this->lookup('1', false);
        $this->expectException(ModelNotFoundException::class);
        $unapproved->find($this->member(), 'ford-live');
    }

    public function testGuestDeniedBeforeRoomLookup(): void
    {
        $repo = $this->spyRepo($this->auth('1', true));
        $lookup = new ExactRoomLookup($repo, $this->auth('1', true));
        try {
            $lookup->find(new User(null), 'ford-live');
            $this->fail('guest must be denied');
        } catch (PermissionDeniedException $e) {
            $this->assertSame(0, $repo->lookups);
        }
    }

    public function testAdminAndModeratorKeepExactAccess(): void
    {
        $lookup = $this->lookup('0', false);
        $admin = $this->member();
        $admin->isAdmin = true;
        $this->assertSame('ford-live', $lookup->find($admin, 'ford-live')->room_key);
        $this->assertSame('not-a-brand-live', $lookup->find($admin, 'not-a-brand-live')->room_key);

        $moderator = $this->member();
        $moderator->permissions[ChatAuthorization::PERM_MODERATE] = true;
        $this->assertSame('ford-live', $lookup->find($moderator, 'ford-live')->room_key);
        $this->assertSame('not-a-brand-live', $lookup->find($moderator, 'not-a-brand-live')->room_key);
        $this->assertTrue((new ChatAuthorization())->canPreviewHiddenChatRooms($moderator));
        $this->assertFalse($moderator->isAdmin());
    }

    public function testShowControllerReturnsOneRoomAndDoesNotSubscribe(): void
    {
        $lookup = $this->lookup('1', true);
        $controller = new ShowLiveRoomController($lookup);
        $request = (new ServerRequest('GET', '/api/flatrate-live-chat/rooms/ford-live'))
            ->withAttribute('actor', $this->member())
            ->withQueryParams(['roomKey' => 'ford-live']);
        $method = new \ReflectionMethod(ShowLiveRoomController::class, 'data');
        $method->setAccessible(true);
        $chat = $method->invoke($controller, $request, new Document());

        $this->assertSame('ford-live', $chat->room_key);
        $this->assertSame(0, $chat->subscribes);
        $this->assertSame(1, $chat->type);
    }

    public function testDirectoryContractStaysSeparateFromExactRoute(): void
    {
        $extend = (string) file_get_contents(dirname(__DIR__) . '/extend.php');
        $this->assertStringContainsString(
            "->get('/flatrate-live-chat/rooms/{roomKey}', 'flatrate-live-chat.rooms.show', ShowLiveRoomController::class)",
            $extend
        );
        $show = (string) file_get_contents(dirname(__DIR__) . '/src/Api/Controllers/ShowLiveRoomController.php');
        $lookup = (string) file_get_contents(dirname(__DIR__) . '/src/Api/ExactRoomLookup.php');
        $this->assertStringNotContainsString('->subscribe(', $show);
        $this->assertStringNotContainsString('->subscribe(', $lookup);
        $this->assertStringNotContainsString('canonicalBrandRoomKeys', $show);
        $this->assertStringNotContainsString('canonicalBrandRoomKeys', $lookup);
        $list = (string) file_get_contents(dirname(__DIR__) . '/src/Api/Controllers/ListChatsController.php');
        $this->assertStringContainsString('queryVisible($actor)', $list);
        $this->assertStringNotContainsString('canonicalBrandRoomKeys', $list);
        $directory = (string) file_get_contents(dirname(__DIR__) . '/src/Api/Controllers/ListLiveChatsController.php');
        $this->assertStringContainsString('listLiveDirectory', $directory);
        $catalog = new RoomCatalog();
        $this->assertCount(45, $catalog->canonicalBrandRoomKeys());
        $this->assertFalse($catalog->isCanonicalBrandRoomKey('not-a-brand-live'));
        $this->assertTrue($catalog->isCanonicalBrandRoomKey('alfa-romeo-live'));
        $this->assertTrue($catalog->isCanonicalBrandRoomKey('genesis-live'));
    }

    private function member(): User
    {
        $user = new User(10);
        $user->permissions = [
            ChatAuthorization::PERM_ENABLED => true,
            ChatAuthorization::PERM_CHAT => true,
        ];

        return $user;
    }

    private function auth(string $beta, bool $approved): ChatAuthorization
    {
        $settings = new ExactRoomArraySettings([
            MemberBetaGate::SETTING_KEY => $beta,
        ]);
        $projection = new class($approved) {
            public function __construct(private bool $approved)
            {
            }

            public function isActive(User $actor): bool
            {
                return $this->approved;
            }
        };
        $gate = new MemberBetaGate($settings, $projection, true);

        return new ChatAuthorization($settings, $gate);
    }

    private function lookup(string $beta, bool $approved): ExactRoomLookup
    {
        $auth = $this->auth($beta, $approved);

        return new ExactRoomLookup($this->spyRepo($auth), $auth);
    }

    private function spyRepo(ChatAuthorization $auth): ChatRepository
    {
        return new class($auth) extends ChatRepository {
            public int $lookups = 0;

            public function __construct(ChatAuthorization $auth)
            {
                parent::__construct($auth);
            }

            public function findByRoomKeyOrFail(string $roomKey, User $actor): Chat
            {
                $this->lookups++;

                return parent::findByRoomKeyOrFail($roomKey, $actor);
            }

            protected function listedRoomByKey(User $actor, string $roomKey): ?Chat
            {
                return null;
            }

            protected function storedRoomByKey(string $roomKey): ?Chat
            {
                $chat = new class extends Chat {
                    public int $subscribes = 0;

                    public function subscribe(User $user)
                    {
                        $this->subscribes++;
                    }
                };
                $chat->type = 1;
                $chat->room_key = $roomKey;
                $chat->visibility = RoomVisibility::HIDDEN;
                $chat->audience = RoomAudience::STAFF_PREVIEW;

                return $chat;
            }
        };
    }
}
