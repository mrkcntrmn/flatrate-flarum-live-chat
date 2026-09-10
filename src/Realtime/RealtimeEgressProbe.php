<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Realtime;

use FlatRate\LiveChat\Observability\RealtimeLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Admin-only fixed-target server-side egress probe.
 * Hardcoded healthz URL only — never accepts client-supplied destinations.
 */
class RealtimeEgressProbe
{
    public const TARGET_URL = 'https://realtime.flatrate.wiki/healthz';
    public const CONNECT_TIMEOUT = 2.0;
    public const TIMEOUT = 5.0;

    /** @var callable|null fn(): array{status:?int, error:?string} */
    private $httpGet;

    public function __construct(
        private ?RealtimeLogger $logger = null,
        ?callable $httpGet = null
    ) {
        $this->logger = $logger ?? new RealtimeLogger();
        $this->httpGet = $httpGet;
    }

    /**
     * @return array{ok: bool, reachable: bool, httpStatus?: int, tlsVerified?: bool, durationMs?: int, category?: string}
     */
    public function probe(): array
    {
        $started = hrtime(true);

        try {
            $result = $this->doGet();
        } catch (\Throwable $e) {
            $this->logger->warning('realtime.egress_probe.error', [
                'errorClass' => get_class($e),
            ]);

            return [
                'ok' => false,
                'reachable' => false,
                'category' => 'internal',
            ];
        }

        $durationMs = (int) max(0, (hrtime(true) - $started) / 1_000_000);

        if ($result['error'] !== null) {
            return [
                'ok' => false,
                'reachable' => false,
                'category' => $result['error'],
                'durationMs' => $durationMs,
            ];
        }

        $status = $result['status'];
        if ($status === 200) {
            return [
                'ok' => true,
                'reachable' => true,
                'httpStatus' => 200,
                'tlsVerified' => true,
                'durationMs' => $durationMs,
            ];
        }

        return [
            'ok' => false,
            'reachable' => true,
            'httpStatus' => $status,
            'tlsVerified' => true,
            'category' => 'unexpected_status',
            'durationMs' => $durationMs,
        ];
    }

    /**
     * @return array{status: ?int, error: ?string}
     */
    private function doGet(): array
    {
        if ($this->httpGet !== null) {
            return ($this->httpGet)();
        }

        $client = new Client([
            'http_errors' => false,
            'allow_redirects' => false,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => self::TIMEOUT,
            'verify' => true,
        ]);

        try {
            $response = $client->get(self::TARGET_URL);
            $status = $response->getStatusCode();
            // Redirects are not followed; treat 3xx as unexpected.
            if ($status >= 300 && $status < 400) {
                return ['status' => $status, 'error' => 'unexpected_status'];
            }

            return ['status' => $status, 'error' => null];
        } catch (ConnectException $e) {
            return ['status' => null, 'error' => $this->classifyConnect($e)];
        } catch (RequestException $e) {
            return ['status' => null, 'error' => $this->classifyRequest($e)];
        } catch (GuzzleException $e) {
            return ['status' => null, 'error' => 'internal'];
        }
    }

    private function classifyConnect(ConnectException $e): string
    {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($msg, 'ssl') || str_contains($msg, 'certificate') || str_contains($msg, 'tls')) {
            return 'tls';
        }

        return 'dns_or_connect';
    }

    private function classifyRequest(RequestException $e): string
    {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($msg, 'ssl') || str_contains($msg, 'certificate') || str_contains($msg, 'tls')) {
            return 'tls';
        }

        return 'dns_or_connect';
    }
}
