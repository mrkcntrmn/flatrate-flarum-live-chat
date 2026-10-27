<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Chat;
use FlatRate\LiveChat\ChatSocket;
use FlatRate\LiveChat\Event\Chat\Deleting as ChatDeleting;
use FlatRate\LiveChat\Event\Chat\Saved as ChatSaved;
use FlatRate\LiveChat\Event\Message\Deleting as MessageDeleting;
use FlatRate\LiveChat\Event\Message\Saved as MessageSaved;
use FlatRate\LiveChat\F2\Listener\PushChatEvents;
use FlatRate\LiveChat\Message;
use FlatRate\LiveChat\Realtime\CentrifugoChannelNamer;
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Realtime\CentrifugoRealtimePublisher;
use FlatRate\LiveChat\Realtime\FakeRealtimePublisher;
use FlatRate\LiveChat\Realtime\NullRealtimePublisher;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

class F2PushChatEventsTest extends TestCase
{
    private ?string $testPrivateKey = null;

    protected function setUp(): void
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($res);
        openssl_pkey_export($res, $this->testPrivateKey);
    }

    public function testF2ListenerClassLoadsWithoutTobscure(): void
    {
        $this->assertTrue(class_exists(PushChatEvents::class));
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/F2/Listener/PushChatEvents.php');
        $this->assertStringNotContainsString('use Tobscure\\', $src);
        $this->assertStringNotContainsString('Tobscure\\JsonApi', $src);
        $this->assertStringNotContainsString('MessageSerializer', $src);
        $this->assertStringNotContainsString('ChatSerializer', $src);
    }

    public function testSubscribeRegistersDomainEvents(): void
    {
        $fake = new FakeRealtimePublisher();
        $listener = new PushChatEvents(new ChatSocket($fake, new CentrifugoChannelNamer(new ChatAuthorization())));
        $listened = [];
        $dispatcher = $this->createMock(Dispatcher::class);
        $dispatcher->expects($this->exactly(4))
            ->method('listen')
            ->willReturnCallback(function ($event) use (&$listened) {
                $listened[] = $event;
            });

        $listener->subscribe($dispatcher);
        $this->assertContains(MessageSaved::class, $listened);
        $this->assertContains(MessageDeleting::class, $listened);
        $this->assertContains(ChatSaved::class, $listened);
        $this->assertContains(ChatDeleting::class, $listened);
    }

    public function testFakePublisherRecordsCreateEditDeleteOnRoomChannel(): void
    {
        putenv('FLATRATE_LIVE_CHAT_FAKE_REALTIME=1');
        $_ENV['FLATRATE_LIVE_CHAT_FAKE_REALTIME'] = '1';

        $fake = new FakeRealtimePublisher();
        $socket = new ChatSocket($fake, new CentrifugoChannelNamer(new ChatAuthorization()));
        $listener = new PushChatEvents($socket);
        $actor = new User(7);

        $chat = new Chat();
        $chat->id = 11;
        $chat->room_key = 'toyota-live';
        $chat->visibility = 'visible';
        $chat->audience = 'members';
        $chat->title = 'Toyota Live';

        $message = new Message();
        $message->id = 101;
        $message->chat_id = 11;
        $message->user_id = 7;
        $message->message = 'hello toyota';
        $message->type = 0;
        $message->is_readed = false;
        $message->created_at = '2020-01-01T00:00:00+00:00';
        $message->chat = $chat;

        $channel = '$flatrate-live-toyota-live';

        $listener->whenMessageSaved(new MessageSaved($message, $actor, [], true));
        $this->assertTrue($fake->hasEvent($channel, 'message.created'));

        $message->message = 'hello toyota edited';
        $message->edited_at = '2020-01-01T00:01:00+00:00';
        $listener->whenMessageSaved(new MessageSaved($message, $actor, [], false));
        $this->assertTrue($fake->hasEvent($channel, 'message.edited'));

        $message->deleted_by = 7;
        $listener->whenMessageDeleting(new MessageDeleting($message, $actor));
        $this->assertTrue($fake->hasEvent($channel, 'message.deleted'));

        $events = $fake->eventsFor($channel);
        $this->assertCount(3, $events);
        $create = $events[0];
        $this->assertSame('toyota-live', $create['payload']['room_key']);
        $this->assertSame(101, $create['payload']['order']);
        $this->assertSame('hello toyota', $create['payload']['response']['message']['data']['attributes']['message']);
        $this->assertSame(7, $create['payload']['response']['message']['data']['attributes']['actor_id']);
    }

    public function testPublishingChatANeverTargetsChatBChannel(): void
    {
        $fake = new FakeRealtimePublisher();
        $socket = new ChatSocket($fake, new CentrifugoChannelNamer(new ChatAuthorization()));
        $listener = new PushChatEvents($socket);
        $actor = new User(3);

        $chatA = new Chat();
        $chatA->id = 21;
        $chatA->room_key = 'gm-live';
        $chatA->visibility = 'visible';
        $chatA->audience = 'members';
        $chatA->title = 'GM Live';

        $message = new Message();
        $message->id = 201;
        $message->chat_id = 21;
        $message->user_id = 3;
        $message->message = 'only gm';
        $message->type = 0;
        $message->chat = $chatA;

        $listener->whenMessageSaved(new MessageSaved($message, $actor, [], true));

        $gm = '$flatrate-live-gm-live';
        $toyota = '$flatrate-live-toyota-live';
        $this->assertTrue($fake->hasEvent($gm, 'message.created'));
        $this->assertFalse($fake->hasEvent($toyota, 'message.created'));
        $this->assertSame([], $fake->eventsFor($toyota));

        // Spoofed client channel name in payload must not redirect the publish target.
        $message->id = 202;
        $message->message = 'still gm';
        $listener->whenMessageSaved(new MessageSaved($message, $actor, [
            'attributes' => ['channel' => '$flatrate-live-toyota-live'],
        ], true));
        $this->assertCount(2, $fake->eventsFor($gm));
        $this->assertSame([], $fake->eventsFor($toyota));
        $this->assertSame('gm-live', $fake->eventsFor($gm)[1]['payload']['room_key']);
    }

    public function testNullPublisherIsFailClosedNoop(): void
    {
        $null = new NullRealtimePublisher();
        $socket = new ChatSocket($null, new CentrifugoChannelNamer(new ChatAuthorization()));
        $listener = new PushChatEvents($socket);
        $actor = new User(1);

        $chat = new Chat();
        $chat->id = 1;
        $chat->room_key = 'community-general-live';
        $chat->visibility = 'visible';
        $chat->audience = 'members';
        $chat->title = 'General';

        $message = new Message();
        $message->id = 1;
        $message->chat_id = 1;
        $message->user_id = 1;
        $message->message = 'noop';
        $message->chat = $chat;

        $listener->whenMessageSaved(new MessageSaved($message, $actor, [], true));
        $this->addToAssertionCount(1);
    }

    public function testCentrifugoPublisherDeniesCrossRoomAndIncompleteConfig(): void
    {
        $calls = [];
        $cfg = new CentrifugoClientConfig(
            'ws://127.0.0.1:8000/connection/websocket',
            'http://127.0.0.1:8000/api/publish',
            'edge-key',
            'api-key',
            $this->testPrivateKey,
            CentrifugoClientConfig::JWT_ALGORITHM,
            CentrifugoClientConfig::DEFAULT_ISSUER,
            CentrifugoClientConfig::DEFAULT_AUDIENCE,
            300,
            300,
            false,
            true
        );
        $this->assertTrue($cfg->isComplete());

        $pub = new CentrifugoRealtimePublisher($cfg, null, function ($url, $headers, $body) use (&$calls) {
            $calls[] = ['url' => $url, 'body' => $body];
            return ['status' => 200, 'body' => '{"result":{}}'];
        });

        $pub->publish('$flatrate-live-toyota-live', 'message.created', ['roomKey' => 'gm-live']);
        $this->assertCount(0, $calls, 'cross-room publish must be denied');

        $incomplete = new CentrifugoRealtimePublisher(new CentrifugoClientConfig(), null, function () use (&$calls) {
            $calls[] = 'should-not-run';
            return ['status' => 200, 'body' => '{"result":{}}'];
        });
        $incomplete->publish('$flatrate-live-toyota-live', 'message.created', ['roomKey' => 'toyota-live']);
        $this->assertCount(0, $calls, 'incomplete config must not publish');
    }

    public function testCentrifugoPublisherDeliversAuthorizedMockPublish(): void
    {
        $cfg = new CentrifugoClientConfig(
            'ws://127.0.0.1:8000/connection/websocket',
            'http://127.0.0.1:8000/api/publish',
            'edge-key',
            'api-key',
            $this->testPrivateKey,
            CentrifugoClientConfig::JWT_ALGORITHM,
            CentrifugoClientConfig::DEFAULT_ISSUER,
            CentrifugoClientConfig::DEFAULT_AUDIENCE,
            300,
            300,
            false,
            true
        );
        $this->assertTrue($cfg->isComplete());

        $calls = [];
        $pub = new CentrifugoRealtimePublisher($cfg, null, function ($url, $headers, $body) use (&$calls) {
            $calls[] = json_decode($body, true);
            return ['status' => 200, 'body' => '{"result":{}}'];
        });

        $fake = new FakeRealtimePublisher();
        $namer = new CentrifugoChannelNamer(new ChatAuthorization());
        $socket = new ChatSocket($fake, $namer);
        $listener = new PushChatEvents($socket);
        $actor = new User(9);

        $chat = new Chat();
        $chat->id = 31;
        $chat->room_key = 'community-general-live';
        $chat->visibility = 'visible';
        $chat->audience = 'members';
        $chat->title = 'General Live';

        $message = new Message();
        $message->id = 501;
        $message->chat_id = 31;
        $message->user_id = 9;
        $message->message = 'authorized delivery';
        $message->chat = $chat;

        $listener->whenMessageSaved(new MessageSaved($message, $actor, [], true));
        $channel = '$flatrate-live-community-general-live';
        $this->assertTrue($fake->hasEvent($channel, 'message.created'));

        $pub->publish($channel, 'message.created', [
            'roomKey' => 'community-general-live',
            'messageId' => 501,
            'order' => 501,
            'eventId' => 'test-event-authorized-1',
        ]);
        $this->assertCount(1, $calls);
        $this->assertSame($channel, $calls[0]['channel']);
        $this->assertSame('community-general-live', $calls[0]['data']['roomKey']);
        $this->assertSame('message.created', $calls[0]['data']['type']);
    }
}
