<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Api\Controllers;

use FlatRate\LiveChat\Realtime\PusherChannelAuthorizer;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/flatrate-live-chat/realtime/auth
 * Body: socket_id, channel_name
 */
class RealtimeAuthController implements RequestHandlerInterface
{
    public function __construct(private PusherChannelAuthorizer $authorizer)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }
        $socketId = (string) Arr::get($body, 'socket_id', '');
        $channelName = (string) Arr::get($body, 'channel_name', '');

        if ($socketId === '' || $channelName === '') {
            return new JsonResponse(['errors' => [['status' => '400', 'code' => 'invalid_parameters']]], 400);
        }

        try {
            $auth = $this->authorizer->authorize($actor, $socketId, $channelName);
            return new JsonResponse($auth);
        } catch (ModelNotFoundException $e) {
            return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found']]], 404);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403);
        }
    }
}
