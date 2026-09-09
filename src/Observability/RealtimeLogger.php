<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Observability;

/**
 * Structured, metric-friendly realtime logs. Never logs message bodies or secrets.
 * Prefers Flarum/Monolog when the container is booted; falls back to error_log.
 */
class RealtimeLogger
{
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    public function warning(string $event, array $context = []): void
    {
        $this->write('warning', $event, $context);
    }

    private function write(string $level, string $event, array $context): void
    {
        $safe = $this->sanitize($context);
        $line = json_encode([
            'component' => 'flatrate-live-chat',
            'level' => $level,
            'event' => $event,
            'context' => $safe,
            'ts' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        // Prefer Flarum's Monolog sink (storage/logs/flarum-*.log) when available.
        try {
            if (function_exists('resolve')) {
                $logger = resolve('log');
                if (is_object($logger) && method_exists($logger, $level)) {
                    $logger->{$level}($line);
                    return;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to error_log.
        }

        error_log($line);
    }

    /** @param array<string,mixed> $context */
    private function sanitize(array $context): array
    {
        $deny = ['secret', 'app_secret', 'password', 'token', 'authorization', 'message', 'body', 'content', 'email', 'ip', 'ip_address'];
        $out = [];
        foreach ($context as $k => $v) {
            $lk = strtolower((string) $k);
            foreach ($deny as $d) {
                if ($lk === $d || str_contains($lk, $d)) {
                    continue 2;
                }
            }
            if (is_scalar($v) || $v === null) {
                $out[$k] = $v;
            } elseif (is_array($v)) {
                $out[$k] = $this->sanitize($v);
            } else {
                $out[$k] = get_debug_type($v);
            }
        }
        return $out;
    }
}
