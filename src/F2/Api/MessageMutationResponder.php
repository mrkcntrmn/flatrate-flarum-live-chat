<?php

/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\F2\Api;

use FlatRate\LiveChat\Commands\DeleteMessage;
use FlatRate\LiveChat\Commands\EditMessage;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared edit/delete response path for independent Flarum 2 handlers.
 */
final class MessageMutationResponder
{
    public function __construct(private BusDispatcher $bus)
    {
    }

    public function edit(ServerRequestInterface $request, $actor): ResponseInterface
    {
        return $this->mutate($request, $actor, false);
    }

    public function delete(ServerRequestInterface $request, $actor): ResponseInterface
    {
        return $this->mutate($request, $actor, true);
    }

    private function mutate(ServerRequestInterface $request, $actor, bool $delete): ResponseInterface
    {
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
