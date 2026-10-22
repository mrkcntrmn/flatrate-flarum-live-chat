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

final class UnsubscribeRoomAction implements RequestHandlerInterface
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
            if ($chat->room_key !== 'community-general-live') {
                $chat->unsubscribe($actor);
            }
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

final class FetchMessagesAction implements RequestHandlerInterface
{
    public function __construct(private BusDispatcher $bus)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $request->getAttribute('actor');
        $params = $request->getQueryParams();
        $chatId = Arr::get($params, 'chat_id', Arr::get($params, 'filter.chat_id'));
        if ($chatId === null || $chatId === '') {
            return new JsonResponse(['errors' => [['status' => '400', 'code' => 'invalid_parameters']]], 400, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $messages = $this->bus->dispatch(new FetchMessage(Arr::get($params, 'query', 0), $actor, (int) $chatId));
        } catch (ModelNotFoundException $e) {
            return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found']]], 404, ['Cache-Control' => 'private, no-store']);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403, ['Cache-Control' => 'private, no-store']);
        }

        $data = [];
        foreach ($messages as $message) {
            $data[] = [
                'type' => 'chat-messages',
                'id' => (string) $message->id,
                'attributes' => [
                    'chatId' => (int) $message->chat_id,
                    'userId' => (int) $message->user_id,
                    'content' => (string) $message->content,
                ],
            ];
        }

        return new JsonResponse(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }
}

final class PostMessageAction implements RequestHandlerInterface
{
    public function __construct(private BusDispatcher $bus)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $request->getAttribute('actor');
        $data = Arr::get($request->getParsedBody(), 'data', []);
        if (!is_array($data)) {
            $data = [];
        }
        unset($data['attributes']['channel'], $data['channel']);

        try {
            $message = $this->bus->dispatch(new PostMessage($actor, $data, null));
        } catch (ModelNotFoundException $e) {
            return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found']]], 404, ['Cache-Control' => 'private, no-store']);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403, ['Cache-Control' => 'private, no-store']);
        }

        return new JsonResponse([
            'data' => [
                'type' => 'chat-messages',
                'id' => (string) $message->id,
                'attributes' => [
                    'chatId' => (int) $message->chat_id,
                    'userId' => (int) $message->user_id,
                    'content' => (string) $message->content,
                ],
            ],
        ], 201, ['Cache-Control' => 'private, no-store']);
    }
}

final class EditMessageAction implements RequestHandlerInterface
{
    public function __construct(private BusDispatcher $bus)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutate($request, false);
    }

    protected function mutate(ServerRequestInterface $request, bool $delete): ResponseInterface
    {
        $actor = $request->getAttribute('actor');
        $id = (int) Arr::get($request->getQueryParams(), 'id');
        $data = Arr::get($request->getParsedBody(), 'data', []);
        if (is_array($data)) {
            unset($data['channel']);
            if (isset($data['attributes']) && is_array($data['attributes'])) {
                unset($data['attributes']['channel'], $data['attributes']['channel_name'], $data['attributes']['channelName']);
            }
        }
        try {
            $message = $delete
                ? $this->bus->dispatch(new DeleteMessage($id, $actor))
                : $this->bus->dispatch(new EditMessage($id, $actor, is_array($data) ? $data : []));
        } catch (ModelNotFoundException $e) {
            return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found']]], 404, ['Cache-Control' => 'private, no-store']);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403, ['Cache-Control' => 'private, no-store']);
        }

        if ($delete) {
            return new JsonResponse(null, 204, ['Cache-Control' => 'private, no-store']);
        }

        return new JsonResponse([
            'data' => ['type' => 'chat-messages', 'id' => (string) $message->id],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}

final class DeleteMessageAction extends EditMessageAction
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutate($request, true);
    }
}
