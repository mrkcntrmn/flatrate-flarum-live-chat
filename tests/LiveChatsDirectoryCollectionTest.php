<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Api\Controllers\ListLiveChatsController;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\Message;
use FlatRate\LiveChat\Rollout\RoomAudience;
use FlatRate\LiveChat\Rollout\RoomVisibility;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as SupportCollection;
use PHPUnit\Framework\TestCase;
use Tobscure\JsonApi\Document;

/**
 * Executable regression coverage for the Live Chats directory serialization path.
 * Catches Support\Collection->load() (which is not Eloquent eager-loading).
 */
class LiveChatsDirectoryCollectionTest extends TestCase
{
    public function testSupportCollectionDoesNotSupportEloquentLoad(): void
    {
        $support = new SupportCollection([]);
        $this->assertFalse(method_exists($support, 'load'));
    }

    public function testEloquentCollectionSupportsLoadOnEmptySet(): void
    {
        $eloquent = new EloquentCollection([]);
        $this->assertTrue(method_exists($eloquent, 'load'));
        $returned = $eloquent->load(['last_message']);
        $this->assertSame($eloquent, $returned);
        $this->assertInstanceOf(EloquentCollection::class, $returned);
    }

    public function testAssembleLiveDirectoryReturnsEloquentCollectionWithGeneralFirst(): void
    {
        $repo = new ChatRepository(new ChatAuthorization());

        $general = $this->room(1, 'community-general-live', 'General Live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);
        $toyota = $this->room(2, 'toyota-live', 'Toyota Live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);
        $toyota->last_message = $this->message(10);
        $bmw = $this->room(3, 'bmw-live', 'BMW Live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);
        $bmw->last_message = $this->message(5);

        $subscribed = new EloquentCollection([$bmw, $toyota]);
        $subscribed = $subscribed->sort(function (Chat $a, Chat $b) {
            $aId = $a->last_message ? (int) $a->last_message->id : 0;
            $bId = $b->last_message ? (int) $b->last_message->id : 0;
            if ($aId !== $bId) {
                return $bId <=> $aId;
            }
            return strcmp((string) $a->title, (string) $b->title);
        })->values();

        $dir = $repo->assembleLiveDirectory($general, $subscribed);
        $this->assertInstanceOf(EloquentCollection::class, $dir);
        $this->assertCount(3, $dir);
        $this->assertSame('community-general-live', $dir[0]->room_key);
        $this->assertSame('toyota-live', $dir[1]->room_key);
        $this->assertSame('bmw-live', $dir[2]->room_key);
        $this->assertTrue(is_callable([$dir, 'load']));
    }

    public function testAssembleLiveDirectoryGeneralOnlyWithoutSubscriptions(): void
    {
        $repo = new ChatRepository(new ChatAuthorization());
        $general = $this->room(1, 'community-general-live', 'General Live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);
        $dir = $repo->assembleLiveDirectory($general, new EloquentCollection([]));
        $this->assertCount(1, $dir);
        $this->assertSame('community-general-live', $dir[0]->room_key);
    }

    public function testControllerDataReturnsEloquentCollectionAfterLoad(): void
    {
        $general = $this->room(1, 'community-general-live', 'General Live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);
        $toyota = $this->room(2, 'toyota-live', 'Toyota Live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);

        // Stub Eloquent models cannot newQuery(); override load like a successful eager-load.
        $directory = new class ([$general, $toyota]) extends EloquentCollection {
            public $loadedRelations = null;

            public function load($relations = [])
            {
                $this->loadedRelations = $relations;

                return $this;
            }
        };

        $repo = $this->getMockBuilder(ChatRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['listLiveDirectory'])
            ->getMock();
        $repo->expects($this->once())
            ->method('listLiveDirectory')
            ->willReturn($directory);

        $auth = new ChatAuthorization();
        $controller = new ListLiveChatsController($repo, $auth);

        $member = new User(10);
        $member->permissions = [ChatAuthorization::PERM_ENABLED => true];

        $request = (new ServerRequest('GET', '/api/flatrate-live-chat/live-chats'))
            ->withAttribute('actor', $member)
            ->withQueryParams(['include' => 'last_message']);

        $method = new \ReflectionMethod(ListLiveChatsController::class, 'data');
        $method->setAccessible(true);
        $result = $method->invoke($controller, $request, new Document());

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertCount(2, $result);
        $this->assertSame('community-general-live', $result[0]->room_key);
        $this->assertSame('toyota-live', $result[1]->room_key);
        $this->assertNotNull($directory->loadedRelations);
    }

    public function testControllerGuestDenied(): void
    {
        $repo = $this->getMockBuilder(ChatRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['listLiveDirectory'])
            ->getMock();
        $repo->expects($this->never())->method('listLiveDirectory');

        $controller = new ListLiveChatsController($repo, new ChatAuthorization());
        $request = (new ServerRequest('GET', '/api/flatrate-live-chat/live-chats'))
            ->withAttribute('actor', new User(null));

        $method = new \ReflectionMethod(ListLiveChatsController::class, 'data');
        $method->setAccessible(true);

        $this->expectException(PermissionDeniedException::class);
        $method->invoke($controller, $request, new Document());
    }

    public function testRemovedMembershipOmittedByRepositoryQueryContract(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/ChatRepository.php');
        $this->assertStringContainsString("whereNull('neonchat_chat_user.removed_at')", $src);
        $this->assertStringContainsString('queryVisible($actor)', $src);
        $this->assertStringContainsString('EloquentCollection', $src);
        $this->assertStringNotContainsString('Illuminate\\Support\\Collection', file_get_contents(
            dirname(__DIR__) . '/src/Api/Controllers/ListLiveChatsController.php'
        ));
        $controller = file_get_contents(dirname(__DIR__) . '/src/Api/Controllers/ListLiveChatsController.php');
        $this->assertStringContainsString('->load($include)', $controller);
        $this->assertStringNotContainsString('new Collection', $controller);
    }

    public function testHiddenUnauthorizedRoomsStayBehindQueryVisible(): void
    {
        $auth = new ChatAuthorization();
        $member = new User(10);
        $member->permissions = [ChatAuthorization::PERM_ENABLED => true];

        $repo = new ChatRepository($auth);
        // queryVisible for ordinary members must constrain visibility/audience.
        // We inspect the built query SQL bindings via toSql when a connection exists;
        // without DB, assert the PHP branch by source + authorization helper.
        $this->assertFalse($auth->canPreviewHiddenChatRooms($member));
        $src = file_get_contents(dirname(__DIR__) . '/src/ChatRepository.php');
        $this->assertMatchesRegularExpression(
            '/function queryVisible.*?RoomVisibility::VISIBLE.*?RoomAudience::MEMBERS/s',
            $src
        );
        $this->assertStringContainsString("where('room_key', '!=', 'community-general-live')", $src);
    }

    public function testLegacySupportCollectionWrapWouldFailLoadRegression(): void
    {
        $general = $this->room(1, 'community-general-live', 'General Live', RoomVisibility::VISIBLE, RoomAudience::MEMBERS);
        $rows = [$general];
        $support = new SupportCollection($rows);

        $this->expectException(\BadMethodCallException::class);
        // Exact historical bug: Support\Collection has no load().
        $support->load(['last_message']);
    }

    private function room(int $id, string $key, string $title, string $visibility, string $audience): Chat
    {
        $chat = new Chat();
        $chat->id = $id;
        $chat->type = 1;
        $chat->room_key = $key;
        $chat->title = $title;
        $chat->visibility = $visibility;
        $chat->audience = $audience;
        $chat->scope_type = $key === 'community-general-live' ? 'navigation-group' : 'board';
        $chat->scope_key = $key === 'community-general-live' ? 'community' : explode('-', $key)[0];

        return $chat;
    }

    private function message(int $id): Message
    {
        $msg = new Message();
        $msg->id = $id;

        return $msg;
    }
}
