<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Api\Throttler\ChatMessage;
use FlatRate\LiveChat\Message;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Realtime\CentrifugoChannelNamer;
use FlatRate\LiveChat\Chat;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use PHPUnit\Framework\TestCase;

class FakeSettings implements SettingsRepositoryInterface
{
    public function get($key, $default = null)
    {
        if (str_contains((string) $key, 'floodgate.number')) {
            return 2;
        }
        if (str_contains((string) $key, 'floodgate.time')) {
            return '1 minute';
        }
        return $default;
    }
}

class FakeRequest
{
    public function __construct(private string $route, private $actor)
    {
    }

    public function getAttribute($k)
    {
        if ($k === 'routeName') {
            return $this->route;
        }
        if ($k === 'actor') {
            return $this->actor;
        }
        return null;
    }
}

class FloodgateAndSerializerSafetyTest extends TestCase
{
    public function testFloodgateBindsChatRoutesOnly(): void
    {
        $throttler = new ChatMessage(new FakeSettings());
        $actor = (object) ['id' => 1];

        $this->assertNull($throttler(new FakeRequest('discussions.create', $actor)));
        $this->assertNull($throttler(new FakeRequest('posts.create', $actor)));

        $src = file_get_contents(dirname(__DIR__) . '/src/Api/Throttler/ChatMessage.php');
        $this->assertStringContainsString('neonchat.chatmessages.post', $src);
        $this->assertStringNotContainsString("'discussions.create'", $src);
        $this->assertStringContainsString('chatRoutes', $src);

        // Non-chat routes must return null (ignored). Returning false would disable all throttlers.
        $this->assertTrue(true);
    }

    public function testMessageSerializerAllowlistNoIp(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/Api/Serializers/MessageSerializer.php');
        $this->assertStringNotContainsString('->getAttributes()', $src);
        $this->assertStringNotContainsString("'ip_address'", $src);
        $this->assertStringNotContainsString('"ip_address"', $src);
        $chatSrc = file_get_contents(dirname(__DIR__) . '/src/Api/Serializers/ChatSerializer.php');
        $this->assertStringNotContainsString('->getAttributes()', $chatSrc);
        $this->assertStringContainsString("'room_key'", $chatSrc);
    }

    public function testMessageBuildDoesNotPersistIp(): void
    {
        $msg = Message::build('hello', 1, new \DateTimeImmutable('now'), 1, '203.0.113.9');
        $this->assertNull($msg->ip_address);
    }

    public function testScriptReexecutionRemovedFromSource(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/js/src/forum/states/ChatState.js');
        $this->assertStringNotContainsString("createElement('script')", $src);
        $this->assertStringContainsString('SCRIPT_REEXECUTION_REMOVAL', $src);
        $dist = file_get_contents(dirname(__DIR__) . '/js/dist/forum.js');
        $this->assertStringNotContainsString("createElement('script')", $dist);
    }

    public function testChatGetChatUserDoesNotAutoJoin(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/Chat.php');
        $this->assertStringContainsString('without auto-join', $src);
        $this->assertStringContainsString('ensureMembership', $src);
        if (preg_match('/function getChatUser\(User \$user\)\s*\{(.*?)\n    \}/s', $src, $m)) {
            $this->assertStringNotContainsString('attach(', $m[1]);
        } else {
            $this->fail('getChatUser not found');
        }
    }

    public function testNoEmailNotificationBlueprints(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));
        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $t = file_get_contents($file->getPathname());
                $this->assertStringNotContainsString('NotificationBlueprint', $t, $file->getPathname());
                $this->assertStringNotContainsString('MailableInterface', $t, $file->getPathname());
            }
        }
    }

    public function testIndexingNoindexInExtend(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/extend.php');
        $this->assertStringContainsString('noindex', $src);
        $this->assertStringContainsString("attachments'] = false", $src);
        $this->assertStringContainsString("email_notifications'] = false", $src);
        $this->assertStringContainsString("'CENTRIFUGO_SELF_HOSTED'", $src);
        $this->assertStringContainsString('flatrate-live-chat/realtime/connect-token', $src);
        $this->assertStringContainsString('flatrate-live-chat/realtime/subscription-token', $src);
        $this->assertStringContainsString('/live/{roomKey}', $src);
    }

    public function testGuestSubscribeDenied(): void
    {
        $auth = new ChatAuthorization();
        $namer = new CentrifugoChannelNamer($auth);
        $guest = new User(null);
        $guest->permissions = [ChatAuthorization::PERM_ENABLED => true];
        $chat = new Chat();
        $chat->type = 1;
        $chat->room_key = 'toyota-live';
        $this->expectException(\Flarum\User\Exception\PermissionDeniedException::class);
        $namer->assertCanSubscribe($guest, $chat);
    }
}
