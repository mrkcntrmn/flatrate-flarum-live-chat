<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Api\Controllers;

use FlatRate\LiveChat\Api\Serializers\ChatUserSerializer;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\ChatRepository;
use Flarum\Api\Controller\AbstractShowController;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

/**
 * POST /api/flatrate-live-chat/rooms/{roomKey}/subscription
 */
class SubscribeRoomController extends AbstractShowController
{
    public $serializer = ChatUserSerializer::class;

    public $include = [
        'last_message',
        'last_message.user',
    ];

    public function __construct(
        private ChatRepository $chats,
        private ChatAuthorization $auth
    ) {
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $this->auth->assertNotGuest($actor);
        $this->auth->assertNotSuspended($actor);

        $roomKey = (string) Arr::get($request->getQueryParams(), 'roomKey', '');
        $chat = $this->chats->findByRoomKeyOrFail($roomKey, $actor);
        $this->auth->assertCanReadRoom($actor, $chat);

        $chat->subscribe($actor);

        return $chat->fresh();
    }
}
