<?php
/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\Api\Controllers;

use FlatRate\LiveChat\Api\Serializers\ChatUserSerializer;
use FlatRate\LiveChat\Auth\ChatAuthorization;
use FlatRate\LiveChat\ChatRepository;
use Flarum\Api\Controller\AbstractListController;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

/**
 * GET /api/flatrate-live-chat/live-chats
 * Directory: FlatRate.wiki General (if authorized) + active subscriptions.
 */
class ListLiveChatsController extends AbstractListController
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

        try {
            $this->auth->assertNotGuest($actor);
            $this->auth->assertNotSuspended($actor);
            $this->auth->assertCanListRooms($actor);
        } catch (PermissionDeniedException $e) {
            throw $e;
        }

        $include = $this->extractInclude($request);
        $rows = $this->chats->listLiveDirectory($actor);

        return (new Collection($rows))->load($include);
    }
}
