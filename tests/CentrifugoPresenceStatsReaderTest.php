<?php

namespace FlatRate\LiveChat\Tests;

use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Realtime\CentrifugoPresenceStatsReader;
use PHPUnit\Framework\TestCase;

class CentrifugoPresenceStatsReaderTest extends TestCase
{
    private function config(): CentrifugoClientConfig
    {
        return new CentrifugoClientConfig(
            CentrifugoClientConfig::DEFAULT_WEBSOCKET_URL,
            CentrifugoClientConfig::DEFAULT_PUBLISH_URL,
            'edge-fixture',
            'api-fixture',
            'private-key-fixture'
        );
    }

    public function testReturnsUniqueUserCountFromPresenceStatsOnly(): void
    {
        $seen = [];
        $reader = new CentrifugoPresenceStatsReader(
            $this->config(),
            null,
            function (string $url, array $headers, string $body) use (&$seen): array {
                $seen = compact('url', 'headers', 'body');
                return [
                    'status' => 200,
                    'body' => json_encode([
                        'result' => [
                            'num_clients' => 9,
                            'num_users' => 4,
                        ],
                    ]),
                ];
            }
        );

        $count = $reader->uniqueUsers('$flatrate-live-community-general-live');

        $this->assertSame(4, $count);
        $this->assertSame('https://realtime.flatrate.wiki/api/presence_stats', $seen['url']);
        $this->assertSame('edge-fixture', $seen['headers']['X-FlatRate-Realtime-Key']);
        $this->assertSame('api-fixture', $seen['headers']['X-API-Key']);
        $this->assertSame(
            ['channel' => '$flatrate-live-community-general-live'],
            json_decode($seen['body'], true)
        );
    }

    public function testFailsClosedForInvalidChannelOrProviderError(): void
    {
        $reader = new CentrifugoPresenceStatsReader(
            $this->config(),
            null,
            fn (): array => ['status' => 503, 'body' => '{}']
        );

        $this->assertNull($reader->uniqueUsers('public-untrusted-channel'));
        $this->assertNull($reader->uniqueUsers('$flatrate-live-community-general-live'));
    }

    public function testRejectsIdentityBearingPresencePayloadShape(): void
    {
        $reader = new CentrifugoPresenceStatsReader(
            $this->config(),
            null,
            fn (): array => [
                'status' => 200,
                'body' => json_encode(['result' => ['presence' => ['client-id' => ['user' => '42']]]]),
            ]
        );

        $this->assertNull($reader->uniqueUsers('$flatrate-live-community-general-live'));
    }
}
