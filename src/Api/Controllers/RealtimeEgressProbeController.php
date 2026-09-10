<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Api\Controllers;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Realtime\RealtimeEgressProbe;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/flatrate-live-chat/realtime/egress-probe
 * Admin-only fixed-target read-only egress probe. No client-supplied URL.
 */
class RealtimeEgressProbeController implements RequestHandlerInterface
{
    public function __construct(
        private RealtimeEgressProbe $probe,
        private ChatAuthorization $auth
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = ['Cache-Control' => 'no-store'];

        $query = $request->getQueryParams();
        if ($this->hasUnsupportedDestinationInput($query) || $this->hasUnsupportedBody($request)) {
            return new JsonResponse([
                'ok' => false,
                'reachable' => false,
                'category' => 'internal',
            ], 400, $headers);
        }

        $actor = RequestUtil::getActor($request);

        try {
            $this->auth->assertAdmin($actor);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse([
                'errors' => [['status' => '403', 'code' => 'permission_denied']],
            ], 403, $headers);
        }

        $result = $this->probe->probe();
        $status = !empty($result['ok']) ? 200 : 503;

        return new JsonResponse($result, $status, $headers);
    }

    /** @param array<string,mixed> $query */
    private function hasUnsupportedDestinationInput(array $query): bool
    {
        foreach (['url', 'host', 'path', 'uri', 'target', 'endpoint'] as $key) {
            if (array_key_exists($key, $query)) {
                return true;
            }
        }

        return false;
    }

    private function hasUnsupportedBody(ServerRequestInterface $request): bool
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || $body === []) {
            return false;
        }
        foreach (['url', 'host', 'path', 'uri', 'target', 'endpoint'] as $key) {
            if (array_key_exists($key, $body)) {
                return true;
            }
        }

        return false;
    }
}
