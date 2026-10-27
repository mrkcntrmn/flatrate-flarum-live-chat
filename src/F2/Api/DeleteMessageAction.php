<?php

/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\F2\Api;

use Flarum\Http\RequestUtil;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DeleteMessageAction implements RequestHandlerInterface
{
    public function __construct(private MessageMutationResponder $mutations)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->mutations->delete($request, RequestUtil::getActor($request));
    }
}
