<?php

/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\F2\Api;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\ChatRepository;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ListLiveChatsAction implements RequestHandlerInterface
{
    public function __construct(
        private ChatRepository $chats,
        private ChatAuthorization $auth
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        try {
            $this->auth->assertNotGuest($actor);
            $this->auth->assertNotSuspended($actor);
            $this->auth->assertCanListRooms($actor);
            $rooms = $this->chats->listLiveDirectory($actor);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(
                ['errors' => [['status' => '403', 'code' => 'permission_denied']]],
                403,
                ['Cache-Control' => 'private, no-store']
            );
        } catch (ModelNotFoundException $e) {
            return new JsonResponse(
                ['errors' => [['status' => '404', 'code' => 'not_found']]],
                404,
                ['Cache-Control' => 'private, no-store']
            );
        }

        $data = [];
        foreach ($rooms as $chat) {
            $data[] = [
                'type' => 'live-rooms',
                'id' => (string) $chat->id,
                'attributes' => [
                    'roomKey' => (string) $chat->room_key,
                    'visibility' => (string) ($chat->visibility ?? ''),
                ],
            ];
        }

        return new JsonResponse(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }
}
