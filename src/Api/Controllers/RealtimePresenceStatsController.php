<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Api\Controllers;

use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\ChatRepository;
use FlatRate\LiveChat\Realtime\CentrifugoChannelNamer;
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Realtime\CentrifugoPresenceStatsReader;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/flatrate-live-chat/realtime/presence-stats
 * Body: roomKey only. Returns count-only online presence for an authorized room.
 */
class RealtimePresenceStatsController implements RequestHandlerInterface
{
    public function __construct(
        private CentrifugoClientConfig $config,
        private CentrifugoPresenceStatsReader $presence,
        private CentrifugoChannelNamer $channels,
        private ChatRepository $chats,
        private ChatAuthorization $auth
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = RequestUtil::getActor($request);
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        // Never accept a raw Centrifugo channel from the browser.
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
            return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found']]], 404, $headers);
        }

        try {
            $this->auth->assertCanSubscribeRealtime($actor, $chat);
            $channel = $this->channels->channelForRoom($chat);
            if ($this->channels->roomKeyFromChannel($channel) !== $roomKey) {
                throw new PermissionDeniedException();
            }
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403, $headers);
        }

        if (!$this->config->isComplete()) {
            return new JsonResponse([
                'roomKey' => $roomKey,
                'liveUserCount' => null,
                'available' => false,
            ], 200, $headers);
        }

        $count = $this->presence->uniqueUsers($channel);

        return new JsonResponse([
            'roomKey' => $roomKey,
            'liveUserCount' => $count,
            'available' => $count !== null,
        ], 200, $headers);
    }
}
