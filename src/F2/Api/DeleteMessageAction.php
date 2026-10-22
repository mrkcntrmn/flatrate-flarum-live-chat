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

final class DeleteMessageAction extends EditMessageAction
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutate($request, true);
    }
}
