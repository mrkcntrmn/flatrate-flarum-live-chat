<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guard: rebrand must not rewrite upstream provenance.
 */
class UpstreamProvenanceTest extends TestCase
{
    private const EXPECTED_SOURCE = 'xelson/flarum-ext-chat';
    private const EXPECTED_COMMIT = 'a7489ac183764eef12969135d6b665ac5eb18272';
    private const EXPECTED_VERSION = 'v1.1.5';
    private const EXPECTED_REPO = 'https://github.com/Xelson/flarum-ext-chat';

    public function testUpstreamMdPinsXelsonSourceAndCommit(): void
    {
        $path = dirname(__DIR__) . '/UPSTREAM.md';
        $this->assertFileExists($path);
        $text = file_get_contents($path);
        $this->assertNotFalse($text);

        $this->assertMatchesRegularExpression(
            '/\|\s*SOURCE\s*\|\s*' . preg_quote(self::EXPECTED_SOURCE, '/') . '\s*\|/',
            $text
        );
        $this->assertMatchesRegularExpression(
            '/\|\s*COMMIT\s*\|\s*' . preg_quote(self::EXPECTED_COMMIT, '/') . '\s*\|/',
            $text
        );
        $this->assertMatchesRegularExpression(
            '/\|\s*VERSION\s*\|\s*' . preg_quote(self::EXPECTED_VERSION, '/') . '\s*\|/',
            $text
        );
        $this->assertMatchesRegularExpression(
            '/\|\s*REPOSITORY\s*\|\s*' . preg_quote(self::EXPECTED_REPO, '/') . '\s*\|/',
            $text
        );
        $this->assertMatchesRegularExpression(
            '/\|\s*LICENSE\s*\|\s*MIT\s*\|/',
            $text
        );
        $this->assertStringContainsString('Push-EDX', $text);
        $this->assertStringContainsString('Xelson', $text);

        // Owned identity must remain separate from SOURCE.
        $this->assertMatchesRegularExpression(
            '/\|\s*OWNED_PACKAGE\s*\|\s*flatrate\/flarum-live-chat\s*\|/',
            $text
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\|\s*SOURCE\s*\|\s*flatrate\/flarum-live-chat\s*\|/',
            $text
        );
    }
}
