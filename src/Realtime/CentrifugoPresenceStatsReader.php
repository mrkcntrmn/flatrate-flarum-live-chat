<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

use FlatRate\LiveChat\Observability\RealtimeLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Count-only Centrifugo presence reader.
 *
 * The forum backend is the only caller. It forwards the existing edge + API
 * credentials to the guarded /api/presence_stats edge route and returns only
 * Centrifugo's unique-user count. Client IDs and user identities are never
 * requested or exposed.
 */
class CentrifugoPresenceStatsReader
{
    private const CONNECT_TIMEOUT = 2.0;
    private const TIMEOUT = 3.0;

    /** @var callable|null fn(string $url, array $headers, string $body): array{status:int,body:string} */
    private $httpPost;

    public function __construct(
        private CentrifugoClientConfig $config,
        private ?RealtimeLogger $logger = null,
        ?callable $httpPost = null
    ) {
        $this->logger = $logger ?? new RealtimeLogger();
        $this->httpPost = $httpPost;
    }

    public function uniqueUsers(string $channel): ?int
    {
        if (!$this->config->isComplete() || $channel === '' || !str_starts_with($channel, '$flatrate-live-')) {
            return null;
        }

        $url = $this->presenceStatsUrl();
        if ($url === null) {
            return null;
        }

        $json = json_encode(['channel' => $channel], JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return null;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-FlatRate-Realtime-Key' => (string) $this->config->edgeKey(),
            'X-API-Key' => (string) $this->config->apiKey(),
        ];

        try {
            $result = $this->doPost($url, $headers, $json);
        } catch (\Throwable $e) {
            $this->logger->warning('realtime.presence_stats.error', [
                'transport' => 'centrifugo',
                'reason' => 'http_transport',
                'errorClass' => get_class($e),
            ]);
            return null;
        }

        $status = (int) ($result['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            $this->logger->warning('realtime.presence_stats.error', [
                'transport' => 'centrifugo',
                'reason' => 'http_status',
                'httpStatus' => $status,
            ]);
            return null;
        }

        $decoded = json_decode((string) ($result['body'] ?? ''), true);
        if (!is_array($decoded) || isset($decoded['error'])) {
            return null;
        }

        $count = $decoded['result']['num_users'] ?? null;
        if (!is_int($count) && !(is_string($count) && ctype_digit($count))) {
            return null;
        }

        $count = (int) $count;
        if ($count < 0) {
            return null;
        }

        return $count;
    }

    private function presenceStatsUrl(): ?string
    {
        $publish = $this->config->publishApiUrl();
        if (!$publish || !$this->config->isPublishUrlSafe($publish)) {
            return null;
        }

        $parts = parse_url($publish);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'] . $port . '/api/presence_stats';
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    private function doPost(string $url, array $headers, string $json): array
    {
        if ($this->httpPost !== null) {
            return ($this->httpPost)($url, $headers, $json);
        }

        $client = new Client([
            'http_errors' => false,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => self::TIMEOUT,
        ]);

        try {
            $response = $client->post($url, [
                'headers' => $headers,
                'body' => $json,
            ]);
        } catch (GuzzleException $e) {
            throw $e;
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    }
}
