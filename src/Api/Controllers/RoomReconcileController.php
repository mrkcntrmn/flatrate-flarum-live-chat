<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Api\Controllers;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Provisioner\ProductionRoomReconcileService;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/flatrate-live-chat/admin/rooms/reconcile
 * Admin-only state-bound transactional canonical room reconcile.
 */
class RoomReconcileController implements RequestHandlerInterface
{
    public function __construct(
        private ProductionRoomReconcileService $service,
        private ChatAuthorization $auth
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = ['Cache-Control' => 'no-store'];

        if ($this->hasUnsupportedDestinationInput($request->getQueryParams())) {
            return new JsonResponse([
                'ok' => false,
                'code' => ProductionRoomReconcileService::ERR_BAD_REQUEST,
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

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $raw = (string) $request->getBody();
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $result = $this->service->reconcile($body);

        return new JsonResponse($result['body'], $result['status'], $headers);
    }

    /** @param array<string,mixed> $query */
    private function hasUnsupportedDestinationInput(array $query): bool
    {
        foreach (['mode', 'profile', 'catalog', 'roomKey', 'room_key', 'url', 'path', 'allowWrites'] as $key) {
            if (array_key_exists($key, $query)) {
                return true;
            }
        }

        return false;
    }
}
