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

        // Must remain Eloquent\Collection — Support\Collection has no load().
        return $this->chats->listLiveDirectory($actor)->load($include);
    }
}
