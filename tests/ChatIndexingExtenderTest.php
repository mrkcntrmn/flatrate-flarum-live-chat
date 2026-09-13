<?php

namespace FlatRate\LiveChat\Tests;

use PHPUnit\Framework\TestCase;

class ChatIndexingExtenderTest extends TestCase
{
    public function testForumFrontendStillRegistersChatRoutesAndScopedNoindex(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/extend.php');

        $this->assertStringContainsString("->route('/chat', 'chat')", $src);
        $this->assertStringContainsString("->route('/live', 'flatrate-live-chat.index')", $src);
        $this->assertStringContainsString("->route('/live/{roomKey}', 'flatrate-live-chat.live')", $src);
        $this->assertStringContainsString("Frontend('forum')", $src);
        $this->assertStringContainsString('ChatIndexingPolicy::shouldNoIndexPath', $src);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $src);
        $this->assertStringContainsString('$request->getUri()->getPath()', $src);
        $this->assertStringContainsString("'flatrate-live-chat.settings.indexing'] = false", $src);
        $this->assertDoesNotMatchRegularExpression(
            '/->content\(function \(Document \$document\) \{\s*\/\/ CHAT_INDEXING=false\s*\$document->head\[\] = \'<meta name="robots" content="noindex, nofollow">\';\s*\}\)/',
            $src
        );
    }
}
