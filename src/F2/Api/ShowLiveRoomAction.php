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

final class ShowLiveRoomAction implements RequestHandlerInterface
{
    public function __construct(private ExactRoomLookup $rooms)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $roomKey = (string) Arr::get($request->getQueryParams(), 'roomKey', '');
        try {
            $chat = $this->rooms->find($actor, $roomKey);
        } catch (ModelNotFoundException $e) {
            return self::error(404, 'not_found');
        } catch (PermissionDeniedException $e) {
            return self::error(403, 'permission_denied');
        }

        return new JsonResponse([
            'data' => [
                'type' => 'live-rooms',
                'id' => (string) $chat->id,
                'attributes' => [
                    'roomKey' => (string) $chat->room_key,
                    'visibility' => (string) ($chat->visibility ?? ''),
                    'audience' => (string) ($chat->audience ?? ''),
                ],
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    private static function error(int $status, string $code): ResponseInterface
    {
        return new JsonResponse(
            ['errors' => [['status' => (string) $status, 'code' => $code]]],
            $status,
            ['Cache-Control' => 'private, no-store']
        );
    }
}
