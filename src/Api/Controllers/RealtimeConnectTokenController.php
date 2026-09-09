<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Api\Controllers;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Realtime\RealtimeTokenIssuer;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/flatrate-live-chat/realtime/connect-token
 * Guest/suspended denied; member → connection JWT.
 */
class RealtimeConnectTokenController implements RequestHandlerInterface
{
    public function __construct(
        private CentrifugoClientConfig $config,
        private RealtimeTokenIssuer $tokens,
        private ChatAuthorization $auth
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = ['Cache-Control' => 'no-store'];

        if (!$this->config->isComplete()) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403, $headers);
        }

        $actor = RequestUtil::getActor($request);

        try {
            $this->auth->assertNotGuest($actor);
            $this->auth->assertNotSuspended($actor);
            $this->auth->assertEnabled($actor);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403, $headers);
        }

        try {
            $issued = $this->tokens->issueConnectionToken((string) $actor->id);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403, $headers);
        }

        return new JsonResponse([
            'token' => $issued['token'],
            'expiresAt' => $issued['expiresAt'],
        ], 200, $headers);
    }
}
