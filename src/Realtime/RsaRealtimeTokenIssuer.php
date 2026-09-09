<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

use Firebase\JWT\JWT;
use Flarum\User\Exception\PermissionDeniedException;

/**
 * RS256 JWT issuer for Centrifugo connection + subscription tokens.
 * Fail closed on missing/invalid private key material.
 */
class RsaRealtimeTokenIssuer implements RealtimeTokenIssuer
{
    public function __construct(
        private CentrifugoClientConfig $config
    ) {
    }

    public function issueConnectionToken(string $subject): array
    {
        $subject = trim($subject);
        if ($subject === '') {
            throw new PermissionDeniedException();
        }
        $ttl = $this->config->connectionTokenTtl();
        $now = time();
        $exp = $now + $ttl;
        $claims = [
            'sub' => $subject,
            'iss' => $this->config->jwtIssuer(),
            'aud' => $this->config->jwtAudience(),
            'iat' => $now,
            'exp' => $exp,
        ];
        return [
            'token' => $this->encode($claims),
            'expiresAt' => $exp,
        ];
    }

    public function issueSubscriptionToken(string $subject, string $channel): array
    {
        $subject = trim($subject);
        $channel = trim($channel);
        if ($subject === '' || $channel === '') {
            throw new PermissionDeniedException();
        }
        // Exact channel only — never mint wildcards or client-supplied extras.
        if (!str_starts_with($channel, CentrifugoChannelNamer::PREFIX)) {
            throw new PermissionDeniedException();
        }
        $ttl = $this->config->subscriptionTokenTtl();
        $now = time();
        $exp = $now + $ttl;
        $claims = [
            'sub' => $subject,
            'iss' => $this->config->jwtIssuer(),
            'aud' => $this->config->jwtAudience(),
            'iat' => $now,
            'exp' => $exp,
            'channel' => $channel,
        ];
        return [
            'token' => $this->encode($claims),
            'expiresAt' => $exp,
        ];
    }

    /** @param array<string,mixed> $claims */
    private function encode(array $claims): string
    {
        $pem = $this->config->jwtPrivateKey();
        if ($pem === null || $pem === '') {
            throw new PermissionDeniedException();
        }
        if (!$this->config->isComplete()) {
            throw new PermissionDeniedException();
        }
        $resource = @openssl_pkey_get_private($pem);
        if ($resource === false) {
            throw new PermissionDeniedException();
        }
        try {
            return JWT::encode($claims, $pem, CentrifugoClientConfig::JWT_ALGORITHM);
        } catch (\Throwable $e) {
            throw new PermissionDeniedException();
        }
    }
}
