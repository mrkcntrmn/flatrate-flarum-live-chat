<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Seo\ChatIndexingPolicy;
use PHPUnit\Framework\TestCase;

class ChatIndexingPolicyTest extends TestCase
{
    /**
     * @dataProvider chatPaths
     */
    public function testChatSurfacesAreNoIndexed(string $path): void
    {
        $this->assertTrue(ChatIndexingPolicy::shouldNoIndexPath($path), $path);
    }

    /**
     * @dataProvider ordinaryForumPaths
     */
    public function testOrdinaryForumPathsAreNotNoIndexed(string $path): void
    {
        $this->assertFalse(ChatIndexingPolicy::shouldNoIndexPath($path), $path);
    }

    public function testQueryStringIsNotPartOfThePathContract(): void
    {
        $this->assertTrue(ChatIndexingPolicy::shouldNoIndexPath('/chat'));
        $this->assertTrue(ChatIndexingPolicy::shouldNoIndexPath('/live'));
        $this->assertFalse(ChatIndexingPolicy::shouldNoIndexPath('/t/gm'));
    }

    public static function chatPaths(): array
    {
        return [
            'CHAT_NO_INDEX_CHAT_ROOT' => ['/chat'],
            'CHAT_NO_INDEX_CHAT_TRAILING_SLASH' => ['/chat/'],
            'CHAT_NO_INDEX_LIVE_ROOT' => ['/live'],
            'CHAT_NO_INDEX_LIVE_TRAILING_SLASH' => ['/live/'],
            'CHAT_NO_INDEX_LIVE_ROOM' => ['/live/general'],
            'live room key' => ['/live/toyota-live'],
        ];
    }

    public static function ordinaryForumPaths(): array
    {
        return [
            'CHAT_NO_INDEX_HOME' => ['/'],
            'CHAT_NO_INDEX_TAG' => ['/t/gm'],
            'CHAT_NO_INDEX_DISCUSSION' => ['/d/5-shop-ratings-partnership'],
            'CHAT_NO_INDEX_PROFILE' => ['/u/tech_0fc71a80'],
            'CHAT_NO_INDEX_CHATTY' => ['/chatty'],
            'CHAT_NO_INDEX_LIVE_CHAT_FALSE_POSITIVE' => ['/live-chat'],
            'CHAT_NO_INDEX_LIVESTREAM' => ['/livestream'],
            'empty' => [''],
            'tags index' => ['/tags'],
            'following' => ['/following'],
        ];
    }
}
