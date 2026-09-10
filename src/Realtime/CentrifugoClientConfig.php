<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

/**
 * Fail-closed Centrifugo client/server config.
 * Never exposes edgeKey, apiKey, privateKey, or publish URL to the forum frontend.
 *
 * Production (PikaPods) supports zero-custom-env via canonical URL defaults and
 * durable secret files under /data/flatrate-live-chat/secrets.
 */
class CentrifugoClientConfig
{
    public const JWT_ALGORITHM = 'RS256';
    public const DEFAULT_ISSUER = 'flatrate-forum';
    public const DEFAULT_AUDIENCE = 'flatrate-realtime';
    public const DEFAULT_CONNECTION_TTL = 300;
    public const DEFAULT_SUBSCRIPTION_TTL = 300;

    public const EXPECTED_WS_SCHEME = 'wss';
    public const EXPECTED_WS_HOST = 'realtime.flatrate.wiki';
    public const EXPECTED_WS_PATH = '/connection/websocket';
    public const EXPECTED_PUBLISH_SCHEME = 'https';
    public const EXPECTED_PUBLISH_HOST = 'realtime.flatrate.wiki';
    public const EXPECTED_PUBLISH_PATH = '/api/publish';

    public const DEFAULT_WEBSOCKET_URL = 'wss://realtime.flatrate.wiki/connection/websocket';
    public const DEFAULT_PUBLISH_URL = 'https://realtime.flatrate.wiki/api/publish';

    public const URL_SOURCE_ENV = 'env';
    public const URL_SOURCE_DEFAULT = 'default';

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
        private bool $allowInsecure = false,
        private string $websocketUrlSource = self::URL_SOURCE_ENV,
        private string $publishUrlSource = self::URL_SOURCE_ENV,
        private string $edgeKeySource = CentrifugoSecretResolver::SOURCE_MISSING,
        private string $apiKeySource = CentrifugoSecretResolver::SOURCE_MISSING,
        private string $jwtPrivateKeySource = CentrifugoSecretResolver::SOURCE_MISSING
    ) {
    }

    /**
     * @param string|null $defaultSecretDirectory Override canonical /data path (tests only).
     */
    public static function fromEnvironment(?string $defaultSecretDirectory = null): self
    {
        $disabled = self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_DISABLED');
        $forceDisabled = ($disabled === '1' || $disabled === 'true');
        $insecure = self::env('FLATRATE_LIVE_CHAT_ALLOW_INSECURE_REALTIME');
        $allowInsecure = ($insecure === '1' || $insecure === 'true');

        $resolver = new CentrifugoSecretResolver(
            $defaultSecretDirectory ?? CentrifugoSecretResolver::DEFAULT_SECRET_DIRECTORY
        );
        $edge = $resolver->resolveEdgeKey();
        $api = $resolver->resolveApiKey();
        $jwt = $resolver->resolveJwtPrivateKey();

        $wsEnv = self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_WS_URL');
        if ($wsEnv !== null && $wsEnv !== '') {
            $websocketUrl = $wsEnv;
            $wsSource = self::URL_SOURCE_ENV;
        } else {
            $websocketUrl = self::DEFAULT_WEBSOCKET_URL;
            $wsSource = self::URL_SOURCE_DEFAULT;
        }

        $publishEnv = self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_PUBLISH_URL');
        if ($publishEnv !== null && $publishEnv !== '') {
            $publishUrl = $publishEnv;
            $publishSource = self::URL_SOURCE_ENV;
        } else {
            $publishUrl = self::DEFAULT_PUBLISH_URL;
            $publishSource = self::URL_SOURCE_DEFAULT;
        }

        $issuer = self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_ISSUER') ?: self::DEFAULT_ISSUER;
        $audience = self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_AUDIENCE') ?: self::DEFAULT_AUDIENCE;
        $connTtl = (int) (self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_CONNECTION_TTL') ?: self::DEFAULT_CONNECTION_TTL);
        $subTtl = (int) (self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_SUBSCRIPTION_TTL') ?: self::DEFAULT_SUBSCRIPTION_TTL);

        return new self(
            $websocketUrl,
            $publishUrl,
            $edge['value'],
            $api['value'],
            $jwt['value'],
            self::JWT_ALGORITHM,
            $issuer,
            $audience,
            $connTtl > 0 ? $connTtl : self::DEFAULT_CONNECTION_TTL,
            $subTtl > 0 ? $subTtl : self::DEFAULT_SUBSCRIPTION_TTL,
            $forceDisabled,
            $allowInsecure,
            $wsSource,
            $publishSource,
            $edge['source'],
            $api['source'],
            $jwt['source']
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
        if ($this->edgeKeySource === CentrifugoSecretResolver::SOURCE_INVALID_FILE
            || $this->apiKeySource === CentrifugoSecretResolver::SOURCE_INVALID_FILE
            || $this->jwtPrivateKeySource === CentrifugoSecretResolver::SOURCE_INVALID_FILE) {
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
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '');

        if ($this->allowInsecure && $this->isLocalhostHost($host)) {
            return $scheme === 'https' || $scheme === 'http';
        }

        if ($scheme !== self::EXPECTED_PUBLISH_SCHEME) {
            return false;
        }
        if ($host !== self::EXPECTED_PUBLISH_HOST) {
            return false;
        }
        if (isset($parts['port'])) {
            return false;
        }
        return $path === self::EXPECTED_PUBLISH_PATH;
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
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '');

        if ($this->allowInsecure && $this->isLocalhostHost($host)) {
            return in_array($scheme, ['wss', 'ws', 'https', 'http'], true);
        }

        if ($scheme !== self::EXPECTED_WS_SCHEME) {
            return false;
        }
        if ($host !== self::EXPECTED_WS_HOST) {
            return false;
        }
        if (isset($parts['port'])) {
            return false;
        }
        return $path === self::EXPECTED_WS_PATH;
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

    public function websocketUrlSource(): string
    {
        return $this->websocketUrlSource;
    }

    public function publishUrlSource(): string
    {
        return $this->publishUrlSource;
    }

    public function edgeKeySource(): string
    {
        return $this->edgeKeySource;
    }

    public function apiKeySource(): string
    {
        return $this->apiKeySource;
    }

    public function jwtPrivateKeySource(): string
    {
        return $this->jwtPrivateKeySource;
    }

    /**
     * Safe forum attributes — never edgeKey, apiKey, privateKey, publish URL, paths, or sources.
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
     * Diagnostic summary without secrets or filesystem paths.
     *
     * @return array<string,mixed>
     */
    public function diagnostic(): array
    {
        $defaultFileMode = $this->edgeKeySource === CentrifugoSecretResolver::SOURCE_DEFAULT_FILE
            && $this->apiKeySource === CentrifugoSecretResolver::SOURCE_DEFAULT_FILE
            && $this->jwtPrivateKeySource === CentrifugoSecretResolver::SOURCE_DEFAULT_FILE;

        return [
            'transportDecision' => 'CENTRIFUGO_SELF_HOSTED',
            'runtimeConfigured' => $this->isComplete(),
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
            'websocketUrlSource' => $this->websocketUrlSource,
            'publishUrlSource' => $this->publishUrlSource,
            'edgeKeySource' => $this->edgeKeySource,
            'apiKeySource' => $this->apiKeySource,
            'jwtPrivateKeySource' => $this->jwtPrivateKeySource,
            'secretMode' => $defaultFileMode ? 'file-fallback' : 'mixed-or-env',
        ];
    }
}
