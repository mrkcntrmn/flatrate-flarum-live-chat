<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

/**
 * Fail-closed Pusher client/server config.
 * productionPusherConfigured=false until complete credentials exist.
 * Never exposes app_secret to the forum frontend.
 */
class PusherClientConfig
{
    public function __construct(
        private ?string $appKey = null,
        private ?string $appSecret = null,
        private ?string $appId = null,
        private ?string $cluster = null,
        private bool $forceDisabled = false
    ) {
    }

    public static function fromEnvironment(): self
    {
        $disabled = self::env('FLATRATE_LIVE_CHAT_PUSHER_DISABLED');
        $forceDisabled = ($disabled === '1' || $disabled === 'true');

        return new self(
            self::env('FLATRATE_LIVE_CHAT_PUSHER_KEY') ?: self::env('PUSHER_APP_KEY'),
            self::env('FLATRATE_LIVE_CHAT_PUSHER_SECRET') ?: self::env('PUSHER_APP_SECRET'),
            self::env('FLATRATE_LIVE_CHAT_PUSHER_APP_ID') ?: self::env('PUSHER_APP_ID'),
            self::env('FLATRATE_LIVE_CHAT_PUSHER_CLUSTER') ?: self::env('PUSHER_APP_CLUSTER') ?: 'mt1',
            $forceDisabled
        );
    }

    private static function env(string $key): ?string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $v = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        }
        if ($v === null || $v === '') {
            return null;
        }
        return (string) $v;
    }

    public function isComplete(): bool
    {
        if ($this->forceDisabled) {
            return false;
        }
        return $this->appKey !== null
            && $this->appSecret !== null
            && $this->appId !== null
            && $this->appKey !== ''
            && $this->appSecret !== ''
            && $this->appId !== '';
    }

    public function productionPusherConfigured(): bool
    {
        return $this->isComplete();
    }

    public function appKey(): ?string
    {
        return $this->appKey;
    }

    public function appSecret(): ?string
    {
        return $this->appSecret;
    }

    public function appId(): ?string
    {
        return $this->appId;
    }

    public function cluster(): ?string
    {
        return $this->cluster;
    }

    /**
     * Safe forum attributes — key/cluster only, never secret/id alone without key.
     *
     * @return array<string,mixed>
     */
    public function forumAttributes(): array
    {
        if (!$this->isComplete()) {
            return [
                'flatrate-live-chat.realtime.transport' => 'PUSHER_CHANNELS',
                'flatrate-live-chat.realtime.configured' => false,
                'flatrate-live-chat.realtime.connect' => false,
                'flatrate-live-chat.realtime.authEndpoint' => '/api/flatrate-live-chat/realtime/auth',
            ];
        }
        return [
            'flatrate-live-chat.realtime.transport' => 'PUSHER_CHANNELS',
            'flatrate-live-chat.realtime.configured' => true,
            'flatrate-live-chat.realtime.connect' => true,
            'flatrate-live-chat.realtime.key' => $this->appKey,
            'flatrate-live-chat.realtime.cluster' => $this->cluster,
            'flatrate-live-chat.realtime.authEndpoint' => '/api/flatrate-live-chat/realtime/auth',
        ];
    }

    /**
     * Diagnostic summary without secrets.
     *
     * @return array<string,mixed>
     */
    public function diagnostic(): array
    {
        return [
            'transportDecision' => 'PUSHER_CHANNELS',
            'transportImplementationStatus' => 'implemented/complete',
            'transportExternalQualification' => 'PENDING',
            'productionPusherConfigured' => false, // CHAT-001C never claims production credentials
            'PUSHER_SELECTED' => false,
            'credentialsComplete' => $this->isComplete(),
            'hasKey' => $this->appKey !== null && $this->appKey !== '',
            'hasSecret' => $this->appSecret !== null && $this->appSecret !== '',
            'hasAppId' => $this->appId !== null && $this->appId !== '',
            'cluster' => $this->cluster,
            'forceDisabled' => $this->forceDisabled,
        ];
    }
}
