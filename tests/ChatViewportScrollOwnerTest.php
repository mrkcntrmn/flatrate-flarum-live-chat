<?php

namespace FlatRate\LiveChat\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Source contracts for CHAT-UI-001 R2 mobile scroll ownership.
 */
class ChatViewportScrollOwnerTest extends TestCase
{
    private function viewportSrc(): string
    {
        return file_get_contents(dirname(__DIR__) . '/js/src/forum/components/ChatViewport.js');
    }

    private function pageLess(): string
    {
        return file_get_contents(dirname(__DIR__) . '/resources/less/forum/ChatPage.less');
    }

    private function viewportLess(): string
    {
        return file_get_contents(dirname(__DIR__) . '/resources/less/forum/ChatViewport.less');
    }

    public function testGetChatWrapperDoesNotUseDocumentElement(): void
    {
        $src = $this->viewportSrc();
        $this->assertStringNotContainsString('document.documentElement', $src);
        $this->assertStringNotContainsString('window.scrollTo', $src);
        $this->assertStringContainsString('getChatWrapper()', $src);
        $this->assertStringContainsString("querySelector('.wrapper')", $src);
        $this->assertStringContainsString('this.scrollElement', $src);
    }

    public function testScrollListenerAttachedToWrapperNotWindow(): void
    {
        $src = $this->viewportSrc();
        $this->assertStringNotContainsString("window.addEventListener", $src);
        $this->assertStringNotContainsString('window : vnode.dom', $src);
        $this->assertStringContainsString('this.scrollElement = vnode.dom', $src);
        $this->assertStringContainsString("this.scrollElement.addEventListener('scroll'", $src);
        $this->assertStringContainsString('this.scrollElement.removeEventListener', $src);
        $this->assertStringContainsString('this.scrollElement = null', $src);
        $this->assertStringContainsString('this.boundScrollListener = null', $src);
    }

    public function testScrollGeometryUsesWrapperCurrentTarget(): void
    {
        $src = $this->viewportSrc();
        $this->assertStringContainsString('e?.currentTarget || this.getChatWrapper()', $src);
        $this->assertStringContainsString('const element = this.getChatWrapper()', $src);
        $this->assertStringContainsString('chatWrapper.scrollHeight <= chatWrapper.clientHeight + 200', $src);
        $this->assertStringNotContainsString('document.documentElement.clientHeight', $src);
    }

    public function testChatViewportFlexColumnCssContract(): void
    {
        $page = $this->pageLess();
        $vp = $this->viewportLess();

        foreach ([$page, $vp] as $css) {
            $this->assertStringContainsString('display: flex', $css);
            $this->assertStringContainsString('flex-direction: column', $css);
        }

        $this->assertStringContainsString('overflow: hidden', $page);
        $this->assertStringContainsString('overflow: hidden', $vp);
        $this->assertMatchesRegularExpression('/\.wrapper\s*\{[^}]*flex:\s*1\s+1\s+auto/s', $page);
        $this->assertMatchesRegularExpression('/\.wrapper\s*\{[^}]*min-height:\s*0/s', $page);
        $this->assertMatchesRegularExpression('/\.wrapper\s*\{[^}]*overflow-y:\s*auto/s', $page);
        $this->assertMatchesRegularExpression('/\.ChatInput\s*\{[^}]*flex:\s*0\s+0\s+auto/s', $page);
        $this->assertMatchesRegularExpression('/\.wrapper\s*\{[^}]*flex:\s*1\s+1\s+auto/s', $vp);
        $this->assertMatchesRegularExpression('/\.wrapper\s*\{[^}]*min-height:\s*0/s', $vp);
        $this->assertMatchesRegularExpression('/\.wrapper\s*\{[^}]*overflow-y:\s*auto/s', $vp);
        $this->assertStringContainsString('100dvh', $page);
        $this->assertStringNotContainsString('70vh', $page);
    }

    public function testCheckUnreadedDoesNotUseLegacyChatIsShownGate(): void
    {
        $src = $this->viewportSrc();
        $this->assertMatchesRegularExpression('/checkUnreaded\(\)\s*\{[\s\S]*?processVisibleUnread\(/', $src);
        if (preg_match('/checkUnreaded\(\)\s*\{([\s\S]*?)\n    \}/', $src, $m) !== 1) {
            $this->fail('checkUnreaded() not found');
        }
        $this->assertStringNotContainsString('chatIsShown', $m[1]);
        $this->assertStringContainsString('getCurrentChat()', $m[1]);
        $this->assertStringNotContainsString('chatIsShown()', $src); // no remaining read-path usage in viewport
    }

    public function testDirectoryEloquentCollectionFixPreserved(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/src/Api/Controllers/ListLiveChatsController.php');
        $repo = file_get_contents(dirname(__DIR__) . '/src/ChatRepository.php');
        $this->assertStringNotContainsString('Illuminate\\Support\\Collection', $controller);
        $this->assertStringContainsString('EloquentCollection', $repo);
        $this->assertStringContainsString('->load($include)', $controller);
    }
}
