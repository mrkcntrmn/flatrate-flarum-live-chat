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

final class FetchMessagesAction implements RequestHandlerInterface
{
    public function __construct(private BusDispatcher $bus)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
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
                    'content' => (string) $message->message,
                ],
            ];
        }

        return new JsonResponse(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }
}
