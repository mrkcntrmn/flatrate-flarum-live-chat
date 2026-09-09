<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Api\Controllers;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Legacy Channels auth endpoint — permanently gone.
 * POST /api/flatrate-live-chat/realtime/auth → 410 Gone
 */
class RealtimeAuthController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'errors' => [[
                'status' => '410',
                'code' => 'gone',
                'detail' => 'Legacy realtime auth superseded by Centrifugo token endpoints',
            ]],
        ], 410, ['Cache-Control' => 'no-store']);
    }
}
