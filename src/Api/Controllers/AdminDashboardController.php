<?php

namespace FlatRate\LiveChat\Api\Controllers;

use FlatRate\LiveChat\Admin\AdminDashboardService;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Admin-only, read-only dashboard API. Returns aggregates and count-only
 * presence for at most one selected canonical room.
 */
final class AdminDashboardController implements RequestHandlerInterface
{
    public function __construct(
        private AdminDashboardService $dashboard,
        private ChatAuthorization $auth
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
        $actor = RequestUtil::getActor($request);

        try {
            $this->auth->assertAdmin($actor);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse([
                'errors' => [['status' => '403', 'code' => 'permission_denied']],
            ], 403, $headers);
        }

        if (!$this->dashboard->enabled()) {
            return new JsonResponse([
                'errors' => [['status' => '404', 'code' => 'dashboard_disabled']],
            ], 404, $headers);
        }

        $query = $request->getQueryParams();
        foreach (array_keys($query) as $key) {
            if ($key !== 'roomKey') {
                return new JsonResponse([
                    'errors' => [['status' => '400', 'code' => 'invalid_request']],
                ], 400, $headers);
            }
        }

        $roomKey = isset($query['roomKey']) ? (string) $query['roomKey'] : null;
        try {
            return new JsonResponse($this->dashboard->snapshot($roomKey), 200, $headers);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'errors' => [['status' => '400', 'code' => 'invalid_room']],
            ], 400, $headers);
        }
    }
}
