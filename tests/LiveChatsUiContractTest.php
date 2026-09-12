<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use PHPUnit\Framework\TestCase;

/**
 * Source + contract guards for CHAT-UI-001 Live Chats navigation.
 */
class LiveChatsUiContractTest extends TestCase
{
    public function testFloatingFrameMountRemoved(): void
    {
        $index = file_get_contents(dirname(__DIR__) . '/js/src/forum/index.js');
        $this->assertStringNotContainsString('ChatFrame', $index);
        $this->assertStringNotContainsString('m.mount', $index);
        $this->assertStringNotContainsString("createElement('div')", $index);
        $this->assertFileDoesNotExist(dirname(__DIR__) . '/js/src/forum/components/ChatFrame.js');
    }

    public function testFloatingCloudControlsAbsentFromSource(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/js/src'));
        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.js')) {
                continue;
            }
            // Icon catalog may list fa-cloud generically; active UI must not.
            if ($file->getFilename() === 'resources.js') {
                continue;
            }
            $t = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('button-fixed-maximize', $t, $file->getPathname());
            $this->assertStringNotContainsString('fas fa-cloud', $t, $file->getPathname());
            $this->assertStringNotContainsString('NeonChatFrame', $t, $file->getPathname());
            $this->assertStringNotContainsString('chatMoveStart', $t, $file->getPathname());
        }

        $lessRoot = dirname(__DIR__) . '/resources/less';
        $this->assertFileDoesNotExist($lessRoot . '/forum/NeonChatFrame.less');
        $forumLess = file_get_contents($lessRoot . '/forum.less');
        $this->assertStringNotContainsString('NeonChatFrame', $forumLess);
    }

    public function testLiveChatsNavPriorityContract(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/js/src/forum/addLiveChatsNavigation.js');
        $this->assertStringContainsString("'LiveChats'", $src);
        $this->assertStringContainsString('HeaderSecondary', $src);
        $this->assertStringContainsString("path: '/live'", $src);
        $this->assertStringContainsString('live_chats_navigation_enabled', $src);
        $this->assertMatchesRegularExpression('/LIVE_CHATS_HEADER_PRIORITY\s*=\s*4/', $src);
        $this->assertMatchesRegularExpression('/DIRECT_MESSAGES_HEADER_PRIORITY\s*=\s*5/', $src);
    }

    public function testDirectoryAndSubscriptionRoutesRegistered(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/extend.php');
        $this->assertStringContainsString('/flatrate-live-chat/live-chats', $src);
        $this->assertStringContainsString('/flatrate-live-chat/rooms/{roomKey}/subscription', $src);
        $this->assertStringContainsString('ListLiveChatsController', $src);
        $this->assertStringContainsString('SubscribeRoomController', $src);
        $this->assertStringContainsString('UnsubscribeRoomController', $src);
        $this->assertStringContainsString("route('/live', 'flatrate-live-chat.index'", $src);
        $this->assertStringContainsString('live_chats_navigation_enabled', $src);
        $this->assertStringContainsString("->default('flatrate-live-chat.live_chats_navigation_enabled', '0')", $src);
    }

    public function testMembershipHelpersPresentAndReadDoesNotSubscribe(): void
    {
        $chat = file_get_contents(dirname(__DIR__) . '/src/Chat.php');
        $this->assertStringContainsString('function subscribe(', $chat);
        $this->assertStringContainsString('function unsubscribe(', $chat);
        $this->assertStringContainsString('without auto-join', $chat);
        if (preg_match('/function getChatUser\(User \$user\)\s*\{(.*?)\n    \}/s', $chat, $m)) {
            $this->assertStringNotContainsString('attach(', $m[1]);
            $this->assertStringNotContainsString('subscribe(', $m[1]);
        } else {
            $this->fail('getChatUser not found');
        }

        $fetch = file_get_contents(dirname(__DIR__) . '/src/Commands/FetchMessageHandler.php');
        $this->assertStringNotContainsString('ensureMembership', $fetch);
        $list = file_get_contents(dirname(__DIR__) . '/src/Api/Controllers/ListChatsController.php');
        $this->assertStringNotContainsString('ensureMembership', $list);
        $dir = file_get_contents(dirname(__DIR__) . '/src/Api/Controllers/ListLiveChatsController.php');
        $this->assertStringNotContainsString('ensureMembership', $dir);
        $this->assertStringNotContainsString('subscribe(', $dir);
    }

    public function testRepositoryDirectoryExcludesUnauthorizedAndUsesPivot(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/src/ChatRepository.php');
        $this->assertStringContainsString('listLiveDirectory', $src);
        $this->assertStringContainsString('community-general-live', $src);
        $this->assertStringContainsString('neonchat_chat_user', $src);
        $this->assertStringContainsString('whereNull(\'neonchat_chat_user.removed_at\')', $src);
        $this->assertStringContainsString('queryVisible', $src);
    }

    public function testMobileFullscreenCssContract(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/resources/less/forum/ChatPage.less');
        $this->assertStringContainsString('100dvh', $css);
        $this->assertStringContainsString('App--live-chat-room', $css);
        $this->assertStringContainsString('position: fixed', $css);
        $this->assertStringContainsString('safe-area-inset', $css);
        $this->assertStringNotContainsString('70vh', $css);
    }

    public function testAuthStillAdminOnlyForReconcile(): void
    {
        $auth = new ChatAuthorization();
        $this->assertTrue(method_exists($auth, 'assertAdmin'));
    }

    public function testLiveChatSingularCopyAndCanonicalRoomLabel(): void
    {
        $en = file_get_contents(dirname(__DIR__) . '/resources/locale/en.yaml');
        $this->assertStringContainsString("live_chats: Live Chat", $en);
        $this->assertStringContainsString('title: Live Chat', $en);
        $this->assertStringContainsString('general_label: FlatRate.wiki Live', $en);
        $this->assertStringContainsString("empty: Live Chat isn't available yet.", $en);
        $this->assertStringNotContainsString('Live Chats', $en);
        $this->assertStringNotContainsString('FlatRate.wiki General', $en);
        $this->assertStringNotContainsString('Your Live Chats', $en);
        $this->assertStringNotContainsString('Welcome to Neon Chat!', $en);
    }

    public function testDirectoryHasNoUnreadBadgeScopeOrSectionHeadings(): void
    {
        $page = file_get_contents(dirname(__DIR__) . '/js/src/forum/components/LiveChatsPage.js');
        $nav = file_get_contents(dirname(__DIR__) . '/js/src/forum/addLiveChatsNavigation.js');
        $less = file_get_contents(dirname(__DIR__) . '/resources/less/forum/ChatPage.less');

        $this->assertStringNotContainsString('LiveChatsPage-unread', $page);
        $this->assertStringNotContainsString('scope_key', $page);
        $this->assertStringNotContainsString('general_heading', $page);
        $this->assertStringNotContainsString('subscriptions_heading', $page);
        $this->assertStringNotContainsString('Your Rooms', $page);
        $this->assertStringNotContainsString('Your Live Chats', $page);
        $this->assertStringNotContainsString('FlatRateLiveChatsNav-badge', $nav);
        $this->assertStringNotContainsString('getUnreadedTotal', $nav);
        $this->assertStringNotContainsString('LiveChatsPage-unread', $less);
        $this->assertStringNotContainsString('FlatRateLiveChatsNav-badge', $less);
    }

    public function testPrimaryRoomKeyUnchangedAndDisplayHelperIsPresentationOnly(): void
    {
        $helper = file_get_contents(dirname(__DIR__) . '/js/src/forum/utils/liveChatPresentation.js');
        $this->assertStringContainsString("PRIMARY_ROOM_KEY = 'community-general-live'", $helper);
        $this->assertStringContainsString('export function displayRoomTitle', $helper);
        $this->assertStringNotContainsString('pushAttributes', $helper);
        $this->assertStringNotContainsString('.save(', $helper);
        $this->assertDoesNotMatchRegularExpression('/\broom_key\s*=(?!=)/', $helper);

        $model = file_get_contents(dirname(__DIR__) . '/js/src/forum/models/Chat.js');
        $this->assertStringContainsString("room_key: Model.attribute('room_key')", $model);
    }
}
