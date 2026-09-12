<?php

namespace FlatRate\LiveChat\Tests;

use PHPUnit\Framework\TestCase;

class LocaleNamespaceContractTest extends TestCase
{
    public function testEveryLocaleRootIsFlatrateLiveChat(): void
    {
        $dir = dirname(__DIR__) . '/resources/locale';
        $files = glob($dir . '/*.yaml');
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $text = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/^xelson-chat:/m', $text, $file);
            $this->assertMatchesRegularExpression('/^flatrate-live-chat:/m', $text, $file);
        }
    }

    public function testSourceDoesNotRequestXelsonChatTranslatorKeys(): void
    {
        $roots = [dirname(__DIR__) . '/js/src', dirname(__DIR__) . '/src'];
        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
                if (!in_array($ext, ['js', 'php'], true)) {
                    continue;
                }
                $text = file_get_contents($file->getPathname());
                $this->assertStringNotContainsString("translator.trans('xelson-chat.", $text, $file->getPathname());
                $this->assertStringNotContainsString('translator.trans("xelson-chat.', $text, $file->getPathname());
            }
        }
    }
}
