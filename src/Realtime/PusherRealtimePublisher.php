<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

use FlatRate\LiveChat\Observability\RealtimeLogger;
use Pusher\Pusher;

/**
 * Pusher Channels publisher. Fail-closed when credentials incomplete.
 * Private channels only — never shared public fanout.
 */
class PusherRealtimePublisher implements RealtimePublisher
{
    private ?Pusher $pusher = null;
    private bool $attempted = false;

    public function __construct(
        private PusherClientConfig $config,
        private ?RealtimeLogger $logger = null
    ) {
        $this->logger = $logger ?? new RealtimeLogger();
    }

    public function publish(string $roomChannelKey, string $event, array $payload): void
    {
        if (!str_starts_with($roomChannelKey, 'private-flatrate-live-')) {
            $this->logger->warning('realtime.publish.deny', [
                'reason' => 'non_private_or_wrong_prefix',
                'channelPrefix' => substr($roomChannelKey, 0, 24),
                'event' => $event,
            ]);
            return;
        }

        $type = EventEnvelope::mapLegacyEvent($event) ?? $event;
        $roomKey = (string) ($payload['room_key'] ?? $payload['roomKey'] ?? '');
        $envelope = EventEnvelope::make($type, $roomKey, $this->normalizePayload($payload), $payload['order'] ?? null);

        $client = $this->client();
        if (!$client) {
            $this->logger->warning('realtime.publish.skip', [
                'reason' => 'credentials_incomplete',
                'event' => $type,
                'channelPrefix' => 'private-flatrate-live-',
            ]);
            return;
        }

        try {
            $client->trigger($roomChannelKey, 'flatrate.live', $envelope);
            $this->logger->info('realtime.publish.allow', [
                'event' => $type,
                'roomKey' => $roomKey,
                'order' => $envelope['order'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('realtime.publish.error', [
                'event' => $type,
                'roomKey' => $roomKey,
                'errorClass' => get_class($e),
            ]);
        }
    }

    private function client(): ?Pusher
    {
        if ($this->attempted) {
            return $this->pusher;
        }
        $this->attempted = true;
        if (!$this->config->isComplete()) {
            return null;
        }
        if (!class_exists(Pusher::class)) {
            $this->logger->warning('realtime.publish.skip', ['reason' => 'pusher_php_missing']);
            return null;
        }
        try {
            $this->pusher = new Pusher(
                (string) $this->config->appKey(),
                (string) $this->config->appSecret(),
                (string) $this->config->appId(),
                ['cluster' => $this->config->cluster() ?: 'mt1', 'useTLS' => true]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('realtime.publish.skip', [
                'reason' => 'pusher_construct_failed',
                'errorClass' => get_class($e),
            ]);
            $this->pusher = null;
        }
        return $this->pusher;
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
