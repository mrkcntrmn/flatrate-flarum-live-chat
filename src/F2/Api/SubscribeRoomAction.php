<?php

/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\F2\Api;

use FlatRate\LiveChat\Api\ExactRoomLookup;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\Commands\DeleteMessage;
use FlatRate\LiveChat\Commands\EditMessage;
use FlatRate\LiveChat\Commands\FetchMessage;
use FlatRate\LiveChat\Commands\PostMessage;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SubscribeRoomAction implements RequestHandlerInterface
{
    public function __construct(
        private ChatRepository $chats,
        private ChatAuthorization $auth
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $roomKey = (string) Arr::get($request->getQueryParams(), 'roomKey', '');
        try {
            $this->auth->assertNotGuest($actor);
            $this->auth->assertNotSuspended($actor);
            $chat = $this->chats->findByRoomKeyOrFail($roomKey, $actor);
            $this->auth->assertCanReadRoom($actor, $chat);
            $chat->subscribe($actor);
        } catch (ModelNotFoundException $e) {
            return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found']]], 404, ['Cache-Control' => 'private, no-store']);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403, ['Cache-Control' => 'private, no-store']);
        }

        return new JsonResponse([
            'data' => ['type' => 'live-rooms', 'id' => (string) $chat->id, 'attributes' => ['roomKey' => (string) $chat->room_key]],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
