<?php

/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\F2\Api;

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

final class PostMessageAction implements RequestHandlerInterface
{
    public function __construct(private BusDispatcher $bus)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $data = Arr::get($request->getParsedBody(), 'data', []);
        if (!is_array($data)) {
            $data = [];
        }
        if (!isset($data['attributes']) || !is_array($data['attributes'])) {
            $data['attributes'] = [];
        }
        unset($data['attributes']['channel'], $data['channel']);

        // Flarum merges the route placeholder into query params. Prefer the body
        // chat_id when present; otherwise use the path id as the room id.
        if (!isset($data['attributes']['chat_id']) || $data['attributes']['chat_id'] === '' || $data['attributes']['chat_id'] === null) {
            $routeId = Arr::get($request->getQueryParams(), 'id');
            if ($routeId !== null && $routeId !== '') {
                $data['attributes']['chat_id'] = (int) $routeId;
            }
        }

        try {
            $message = $this->bus->dispatch(new PostMessage($actor, $data, null));
        } catch (ModelNotFoundException $e) {
            return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found']]], 404, ['Cache-Control' => 'private, no-store']);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403, ['Cache-Control' => 'private, no-store']);
        }

        return new JsonResponse([
            'data' => ChatMessageJson::resource($message),
        ], 201, ['Cache-Control' => 'private, no-store']);
    }
}
