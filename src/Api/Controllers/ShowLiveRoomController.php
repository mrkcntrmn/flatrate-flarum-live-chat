<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Api\Controllers;

use FlatRate\LiveChat\Api\ExactRoomLookup;
use FlatRate\LiveChat\Api\Serializers\ChatUserSerializer;
use Flarum\Api\Controller\AbstractShowController;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

/**
 * GET /api/flatrate-live-chat/rooms/{roomKey}
 *
 * Returns the one requested room when the actor may read it.
 * Does not create a room subscription and does not return the hidden room catalog.
 */
class ShowLiveRoomController extends AbstractShowController
{
    public $serializer = ChatUserSerializer::class;

    public $include = [
        'last_message',
        'last_message.user',
    ];

    public function __construct(private ExactRoomLookup $rooms)
    {
    }

    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $roomKey = (string) Arr::get($request->getQueryParams(), 'roomKey', '');

        return $this->rooms->find($actor, $roomKey);
    }
}
