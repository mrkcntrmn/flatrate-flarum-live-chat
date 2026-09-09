<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

use FlatRate\LiveChat\Observability\RealtimeLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Centrifugo publisher via FlatRate publish edge.
 * Best-effort: never throws. Private `$flatrate-live-*` channels only.
 */
class CentrifugoRealtimePublisher implements RealtimePublisher
{
    private const CONNECT_TIMEOUT = 2.0;
    private const TIMEOUT = 3.0;

    /** @var callable|null fn(string $url, array $headers, string $body): array{status:int,body:string} */
    private $httpPost;

    public function __construct(
        private CentrifugoClientConfig $config,
        private ?RealtimeLogger $logger = null,
        ?callable $httpPost = null,
        private ?CentrifugoChannelNamer $channels = null
    ) {
        $this->logger = $logger ?? new RealtimeLogger();
        $this->httpPost = $httpPost;
        $this->channels = $channels ?? new CentrifugoChannelNamer(new \FlatRate\LiveChat\Auth\ChatAuthorization());
    }

    public function publish(string $roomChannelKey, string $event, array $payload): void
    {
        try {
            $this->publishInternal($roomChannelKey, $event, $payload);
        } catch (\Throwable $e) {
            $this->logger->warning('realtime.publish.error', [
                'transport' => 'centrifugo',
                'errorClass' => get_class($e),
            ]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function publishInternal(string $roomChannelKey, string $event, array $payload): void
    {
        $type = EventEnvelope::mapLegacyEvent($event) ?? $event;
        $roomKey = (string) ($payload['room_key'] ?? $payload['roomKey'] ?? '');

        $channelRoomKey = $this->channels->roomKeyFromChannel($roomChannelKey);
        if ($channelRoomKey === null || $roomKey === '' || $channelRoomKey !== $roomKey) {
            $this->logger->warning('realtime.publish.deny', [
                'transport' => 'centrifugo',
                'reason' => 'channel_room_mismatch',
                'event' => $type,
                'channelPrefix' => substr($roomChannelKey, 0, 24),
            ]);
            return;
        }

        if (!$this->config->isComplete()) {
            $this->logger->warning('realtime.publish.skip', [
                'transport' => 'centrifugo',
                'reason' => 'credentials_incomplete',
                'event' => $type,
            ]);
            return;
        }

        $injectedEventId = isset($payload['eventId']) ? (string) $payload['eventId'] : null;
        $envelope = EventEnvelope::make(
            $type,
            $roomKey,
            $this->normalizePayload($payload),
            isset($payload['order']) ? (int) $payload['order'] : null,
            $injectedEventId
        );

        $body = [
            'channel' => $roomChannelKey,
            'data' => $envelope,
            'idempotency_key' => 'event:' . $envelope['eventId'],
        ];

        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->logger->warning('realtime.publish.error', [
                'transport' => 'centrifugo',
                'reason' => 'json_encode_failed',
                'event' => $type,
            ]);
            return;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-FlatRate-Realtime-Key' => (string) $this->config->edgeKey(),
            'X-API-Key' => (string) $this->config->apiKey(),
        ];

        $url = (string) $this->config->publishApiUrl();
        $ok = $this->postOnce($url, $headers, $json, $type, $roomKey);
        if (!$ok) {
            // One bounded retry: same body (same eventId + idempotency_key).
            $this->postOnce($url, $headers, $json, $type, $roomKey);
        }
    }

    /**
     * @param array<string,string> $headers
     */
    private function postOnce(string $url, array $headers, string $json, string $type, string $roomKey): bool
    {
        try {
            $result = $this->doPost($url, $headers, $json);
        } catch (\Throwable $e) {
            $this->logger->warning('realtime.publish.error', [
                'transport' => 'centrifugo',
                'reason' => 'http_transport',
                'event' => $type,
                'roomKey' => $roomKey,
                'errorClass' => get_class($e),
            ]);
            return false;
        }

        $status = (int) ($result['status'] ?? 0);
        $raw = (string) ($result['body'] ?? '');

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('realtime.publish.error', [
                'transport' => 'centrifugo',
                'reason' => 'http_status',
                'event' => $type,
                'roomKey' => $roomKey,
                'httpStatus' => $status,
            ]);
            return false;
        }

        if ($raw === '') {
            $this->logger->warning('realtime.publish.error', [
                'transport' => 'centrifugo',
                'reason' => 'empty_2xx_body',
                'event' => $type,
                'roomKey' => $roomKey,
                'httpStatus' => $status,
            ]);
            return false;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            $this->logger->warning('realtime.publish.error', [
                'transport' => 'centrifugo',
                'reason' => 'invalid_json_or_non_object',
                'event' => $type,
                'roomKey' => $roomKey,
                'httpStatus' => $status,
            ]);
            return false;
        }

        if (isset($decoded['error'])) {
            $this->logger->warning('realtime.publish.error', [
                'transport' => 'centrifugo',
                'reason' => 'centrifugo_error_object',
                'event' => $type,
                'roomKey' => $roomKey,
                'httpStatus' => $status,
            ]);
            return false;
        }

        if (!array_key_exists('result', $decoded)) {
            $this->logger->warning('realtime.publish.error', [
                'transport' => 'centrifugo',
                'reason' => 'missing_result',
                'event' => $type,
                'roomKey' => $roomKey,
                'httpStatus' => $status,
            ]);
            return false;
        }

        $this->logger->info('realtime.publish.allow', [
            'transport' => 'centrifugo',
            'event' => $type,
            'roomKey' => $roomKey,
        ]);
        return true;
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

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function normalizePayload(array $payload): array
    {
        $out = [];
        if (isset($payload['message_id'])) {
            $out['messageId'] = $payload['message_id'];
        }
        if (isset($payload['messageId'])) {
            $out['messageId'] = $payload['messageId'];
        }
        if (isset($payload['user_id'])) {
            $out['userId'] = $payload['user_id'];
        }
        if (isset($payload['userId'])) {
            $out['userId'] = $payload['userId'];
        }
        foreach (['order', 'createdAt', 'editedAt', 'deletedAt', 'title', 'visibility', 'audience', 'roomKey'] as $k) {
            if (array_key_exists($k, $payload)) {
                $out[$k] = $payload[$k];
            }
        }
        return $out;
    }
}
