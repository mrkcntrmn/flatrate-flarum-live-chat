<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

/**
 * Resolves Centrifugo secret material with env / explicit-file / canonical /data fallback.
 * Never logs or returns secret content in diagnostics beyond source classification.
 */
class CentrifugoSecretResolver
{
    public const DEFAULT_SECRET_DIRECTORY = '/data/flatrate-live-chat/secrets';

    public const EDGE_KEY_FILENAME = 'centrifugo-edge-key';
    public const API_KEY_FILENAME = 'centrifugo-api-key';
    public const JWT_PRIVATE_KEY_FILENAME = 'centrifugo-jwt-private-key.pem';

    public const MAX_KEY_BYTES = 4096;
    public const MAX_PEM_BYTES = 65536;

    public const SOURCE_ENV_VALUE = 'env-value';
    public const SOURCE_EXPLICIT_FILE = 'explicit-file';
    public const SOURCE_DEFAULT_FILE = 'default-file';
    public const SOURCE_MISSING = 'missing';
    public const SOURCE_INVALID_FILE = 'invalid-file';

    public function __construct(
        private string $defaultSecretDirectory = self::DEFAULT_SECRET_DIRECTORY
    ) {
    }

    public function defaultSecretDirectory(): string
    {
        return $this->defaultSecretDirectory;
    }

    /**
     * @return array{value: ?string, source: string}
     */
    public function resolveEdgeKey(): array
    {
        return $this->resolveScalarSecret(
            'FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY',
            'FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY_FILE',
            self::EDGE_KEY_FILENAME,
            self::MAX_KEY_BYTES,
            false
        );
    }

    /**
     * @return array{value: ?string, source: string}
     */
    public function resolveApiKey(): array
    {
        return $this->resolveScalarSecret(
            'FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY',
            'FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY_FILE',
            self::API_KEY_FILENAME,
            self::MAX_KEY_BYTES,
            false
        );
    }

    /**
     * @return array{value: ?string, source: string}
     */
    public function resolveJwtPrivateKey(): array
    {
        $direct = self::env('FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY');
        if ($direct !== null && $direct !== '') {
            $normalized = str_replace(["\\n", "\r\n"], "\n", $direct);
            if (!$this->isValidPemMaterial($normalized)) {
                return ['value' => null, 'source' => self::SOURCE_INVALID_FILE];
            }

            return ['value' => $normalized, 'source' => self::SOURCE_ENV_VALUE];
        }

        return $this->resolveScalarSecret(
            null,
            'FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY_FILE',
            self::JWT_PRIVATE_KEY_FILENAME,
            self::MAX_PEM_BYTES,
            true
        );
    }

    /**
     * @return array{value: ?string, source: string}
     */
    private function resolveScalarSecret(
        ?string $envName,
        string $fileEnvName,
        string $defaultFilename,
        int $maxBytes,
        bool $allowMultiline
    ): array {
        if ($envName !== null) {
            $direct = self::env($envName);
            if ($direct !== null && $direct !== '') {
                $normalized = $this->normalizeScalar($direct, $allowMultiline);
                if ($normalized === null) {
                    return ['value' => null, 'source' => self::SOURCE_INVALID_FILE];
                }

                return ['value' => $normalized, 'source' => self::SOURCE_ENV_VALUE];
            }
        }

        $explicitPath = self::env($fileEnvName);
        if ($explicitPath !== null && $explicitPath !== '') {
            $loaded = $this->readSecretFile($explicitPath, $maxBytes, $allowMultiline);
            if ($loaded === null) {
                // Explicit file path set but unusable — do not fall through.
                return ['value' => null, 'source' => self::SOURCE_INVALID_FILE];
            }

            return ['value' => $loaded, 'source' => self::SOURCE_EXPLICIT_FILE];
        }

        $defaultPath = rtrim($this->defaultSecretDirectory, '/') . '/' . $defaultFilename;
        $loaded = $this->readSecretFile($defaultPath, $maxBytes, $allowMultiline);
        if ($loaded === null) {
            return ['value' => null, 'source' => self::SOURCE_MISSING];
        }

        return ['value' => $loaded, 'source' => self::SOURCE_DEFAULT_FILE];
    }

    private function readSecretFile(string $path, int $maxBytes, bool $allowMultiline): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        if (@is_link($path)) {
            return null;
        }
        if (!@is_file($path) || @is_dir($path)) {
            return null;
        }
        if (!@is_readable($path)) {
            return null;
        }

        $size = @filesize($path);
        if ($size === false || $size <= 0 || $size > $maxBytes) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        if (strlen($raw) > $maxBytes) {
            return null;
        }
        if (str_contains($raw, "\0")) {
            return null;
        }

        return $this->normalizeScalar($raw, $allowMultiline);
    }

    private function normalizeScalar(string $raw, bool $allowMultiline): ?string
    {
        if (str_contains($raw, "\0")) {
            return null;
        }

        // Trim surrounding transport whitespace and a single trailing CR/LF sequence.
        $value = preg_replace('/\A\s+/', '', $raw) ?? $raw;
        $value = preg_replace('/\r?\n\z/', '', $value) ?? $value;
        $value = rtrim($value, " \t");

        if ($value === '') {
            return null;
        }

        if (!$allowMultiline) {
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                return null;
            }
            if (strlen($value) > self::MAX_KEY_BYTES) {
                return null;
            }

            return $value;
        }

        if (!$this->isValidPemMaterial($value)) {
            return null;
        }

        return $value;
    }

    private function isValidPemMaterial(string $value): bool
    {
        if ($value === '' || str_contains($value, "\0")) {
            return false;
        }
        if (strlen($value) > self::MAX_PEM_BYTES) {
            return false;
        }
        // Structural PEM markers only; cryptographic validation stays in token issuer.
        if (!str_contains($value, 'BEGIN') || !str_contains($value, 'PRIVATE KEY')) {
            return false;
        }

        return true;
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
}
