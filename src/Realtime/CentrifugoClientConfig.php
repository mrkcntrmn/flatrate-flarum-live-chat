<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

/**
 * Fail-closed Centrifugo client/server config.
 * Never exposes edgeKey, apiKey, privateKey, or publish URL to the forum frontend.
 */
class CentrifugoClientConfig
{
    public const JWT_ALGORITHM = 'RS256';
    public const DEFAULT_ISSUER = 'flatrate-forum';
    public const DEFAULT_AUDIENCE = 'flatrate-realtime';
    public const DEFAULT_CONNECTION_TTL = 300;
    public const DEFAULT_SUBSCRIPTION_TTL = 300;

    public function __construct(
        private ?string $websocketUrl = null,
        private ?string $publishApiUrl = null,
        private ?string $edgeKey = null,
        private ?string $apiKey = null,
        private ?string $jwtPrivateKey = null,
        private string $jwtAlgorithm = self::JWT_ALGORITHM,
        private string $jwtIssuer = self::DEFAULT_ISSUER,
        private string $jwtAudience = self::DEFAULT_AUDIENCE,
        private int $connectionTokenTtl = self::DEFAULT_CONNECTION_TTL,
        private int $subscriptionTokenTtl = self::DEFAULT_SUBSCRIPTION_TTL,
        private bool $forceDisabled = false,
        private bool $allowInsecure = false
    ) {
    }

    public static function fromEnvironment(): self
    {
        $disabled = self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_DISABLED');
        $forceDisabled = ($disabled === '1' || $disabled === 'true');
        $insecure = self::env('FLATRATE_LIVE_CHAT_ALLOW_INSECURE_REALTIME');
        $allowInsecure = ($insecure === '1' || $insecure === 'true');

        $jwtKey = self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY');
        if ($jwtKey === null || $jwtKey === '') {
            $path = self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY_FILE');
            if ($path !== null && $path !== '' && is_readable($path)) {
                $contents = file_get_contents($path);
                $jwtKey = $contents !== false ? trim($contents) : null;
            }
        } else {
            // Support escaped newlines in env values.
            $jwtKey = str_replace(["\\n", "\r\n"], "\n", $jwtKey);
        }

        $issuer = self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_ISSUER') ?: self::DEFAULT_ISSUER;
        $audience = self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_AUDIENCE') ?: self::DEFAULT_AUDIENCE;
        $connTtl = (int) (self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_CONNECTION_TTL') ?: self::DEFAULT_CONNECTION_TTL);
        $subTtl = (int) (self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_SUBSCRIPTION_TTL') ?: self::DEFAULT_SUBSCRIPTION_TTL);

        return new self(
            self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_WS_URL'),
            self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_PUBLISH_URL'),
            self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY'),
            self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY'),
            $jwtKey,
            self::JWT_ALGORITHM,
            $issuer,
            $audience,
            $connTtl > 0 ? $connTtl : self::DEFAULT_CONNECTION_TTL,
            $subTtl > 0 ? $subTtl : self::DEFAULT_SUBSCRIPTION_TTL,
            $forceDisabled,
            $allowInsecure
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
        if ($this->websocketUrl === null || $this->websocketUrl === '') {
            return false;
        }
        if ($this->publishApiUrl === null || $this->publishApiUrl === '') {
            return false;
        }
        if ($this->edgeKey === null || $this->edgeKey === '') {
            return false;
        }
        if ($this->apiKey === null || $this->apiKey === '') {
            return false;
        }
        if ($this->jwtPrivateKey === null || $this->jwtPrivateKey === '') {
            return false;
        }
        if ($this->jwtAlgorithm !== self::JWT_ALGORITHM) {
            return false;
        }
        if ($this->jwtIssuer === '' || $this->jwtAudience === '') {
            return false;
        }
        if ($this->connectionTokenTtl <= 0 || $this->subscriptionTokenTtl <= 0) {
            return false;
        }
        return $this->urlsAreTlsSafe();
    }

    /**
     * Production URLs must use TLS. Localhost http/ws allowed only with allow-insecure.
     */
    public function urlsAreTlsSafe(): bool
    {
        return $this->isPublishUrlSafe((string) $this->publishApiUrl)
            && $this->isWebsocketUrlSafe((string) $this->websocketUrl);
    }

    public function isPublishUrlSafe(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ($scheme === 'https') {
            return true;
        }
        if ($scheme === 'http' && $this->allowInsecure && $this->isLocalhostHost($host)) {
            return true;
        }
        // Explicitly reject insecure realtime.flatrate.wiki (and any non-TLS non-local).
        return false;
    }

    public function isWebsocketUrlSafe(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ($scheme === 'wss' || $scheme === 'https') {
            return true;
        }
        if (($scheme === 'ws' || $scheme === 'http') && $this->allowInsecure && $this->isLocalhostHost($host)) {
            return true;
        }
        return false;
    }

    private function isLocalhostHost(string $host): bool
    {
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || $host === '[::1]';
    }

    public function websocketUrl(): ?string
    {
        return $this->websocketUrl;
    }

    public function publishApiUrl(): ?string
    {
        return $this->publishApiUrl;
    }

    public function edgeKey(): ?string
    {
        return $this->edgeKey;
    }

    public function apiKey(): ?string
    {
        return $this->apiKey;
    }

    public function jwtPrivateKey(): ?string
    {
        return $this->jwtPrivateKey;
    }

    public function jwtAlgorithm(): string
    {
        return $this->jwtAlgorithm;
    }

    public function jwtIssuer(): string
    {
        return $this->jwtIssuer;
    }

    public function jwtAudience(): string
    {
        return $this->jwtAudience;
    }

    public function connectionTokenTtl(): int
    {
        return $this->connectionTokenTtl;
    }

    public function subscriptionTokenTtl(): int
    {
        return $this->subscriptionTokenTtl;
    }

    public function allowInsecure(): bool
    {
        return $this->allowInsecure;
    }

    public function forceDisabled(): bool
    {
        return $this->forceDisabled;
    }

    /**
     * Safe forum attributes — never edgeKey, apiKey, privateKey, publish URL.
     *
     * @return array<string,mixed>
     */
    public function forumAttributes(): array
    {
        $base = [
            'flatrate-live-chat.realtime.transport' => 'CENTRIFUGO',
            'flatrate-live-chat.realtime.connectTokenEndpoint' => '/api/flatrate-live-chat/realtime/connect-token',
            'flatrate-live-chat.realtime.subscriptionTokenEndpoint' => '/api/flatrate-live-chat/realtime/subscription-token',
        ];

        if (!$this->isComplete()) {
            return $base + [
                'flatrate-live-chat.realtime.configured' => false,
                'flatrate-live-chat.realtime.connect' => false,
            ];
        }

        return $base + [
            'flatrate-live-chat.realtime.configured' => true,
            'flatrate-live-chat.realtime.connect' => true,
            'flatrate-live-chat.realtime.websocketUrl' => $this->websocketUrl,
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
            'transportDecision' => 'CENTRIFUGO_SELF_HOSTED',
            'transportImplementationStatus' => 'implemented/complete',
            'transportExternalQualification' => 'PENDING',
            'productionCentrifugoConfigured' => false,
            'credentialsComplete' => $this->isComplete(),
            'hasWebsocketUrl' => $this->websocketUrl !== null && $this->websocketUrl !== '',
            'hasPublishUrl' => $this->publishApiUrl !== null && $this->publishApiUrl !== '',
            'hasEdgeKey' => $this->edgeKey !== null && $this->edgeKey !== '',
            'hasApiKey' => $this->apiKey !== null && $this->apiKey !== '',
            'hasJwtPrivateKey' => $this->jwtPrivateKey !== null && $this->jwtPrivateKey !== '',
            'jwtAlgorithm' => $this->jwtAlgorithm,
            'jwtIssuer' => $this->jwtIssuer,
            'jwtAudience' => $this->jwtAudience,
            'connectionTokenTtl' => $this->connectionTokenTtl,
            'subscriptionTokenTtl' => $this->subscriptionTokenTtl,
            'urlsTlsSafe' => $this->urlsAreTlsSafe(),
            'allowInsecure' => $this->allowInsecure,
            'forceDisabled' => $this->forceDisabled,
        ];
    }
}
