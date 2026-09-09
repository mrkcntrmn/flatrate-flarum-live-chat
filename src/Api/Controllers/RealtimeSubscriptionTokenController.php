<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Api\Controllers;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\Realtime\CentrifugoChannelNamer;
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Realtime\RealtimeTokenIssuer;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/flatrate-live-chat/realtime/subscription-token
 * Body: roomKey only (reject channel param). Mint channel-bound JWT after ACL.
 */
class RealtimeSubscriptionTokenController implements RequestHandlerInterface
{
    public function __construct(
        private CentrifugoClientConfig $config,
        private RealtimeTokenIssuer $tokens,
        private CentrifugoChannelNamer $channels,
        private ChatRepository $chats,
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
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        // Never accept client-supplied channel names.
        if (Arr::has($body, 'channel') || Arr::has($body, 'channel_name') || Arr::has($body, 'channelName')) {
            return new JsonResponse(['errors' => [['status' => '400', 'code' => 'invalid_parameters']]], 400, $headers);
        }

        $roomKey = (string) Arr::get($body, 'roomKey', '');
        if ($roomKey === '') {
            return new JsonResponse(['errors' => [['status' => '400', 'code' => 'invalid_parameters']]], 400, $headers);
        }

        if (!$actor->id) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403, $headers);
        }

        try {
            $chat = $this->chats->findByRoomKeyOrFail($roomKey, $actor);
        } catch (ModelNotFoundException $e) {
            // Ordinary member + hidden room → 404 (repository visibility filter).
            return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found']]], 404, $headers);
        }

        try {
            $this->auth->assertCanSubscribeRealtime($actor, $chat);
            $channel = $this->channels->channelForRoom($chat);
            if ($this->channels->roomKeyFromChannel($channel) !== $roomKey) {
                throw new PermissionDeniedException();
            }
            $issued = $this->tokens->issueSubscriptionToken((string) $actor->id, $channel);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403, $headers);
        }

        return new JsonResponse([
            'token' => $issued['token'],
            'expiresAt' => $issued['expiresAt'],
            'channel' => $channel,
        ], 200, $headers);
    }
}
