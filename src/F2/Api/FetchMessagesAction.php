<?php

/*
 * This file is part of flatrate/flarum-live-chat
 */

namespace FlatRate\LiveChat\F2\Api;

use FlatRate\LiveChat\Commands\FetchMessage;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FetchMessagesAction implements RequestHandlerInterface
{
    public function __construct(private BusDispatcher $bus, private SlugManager $slugManager)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();
        $chatId = Arr::get($params, 'chat_id', Arr::get($params, 'filter.chat_id'));
        if ($chatId === null || $chatId === '') {
            return new JsonResponse(['errors' => [['status' => '400', 'code' => 'invalid_parameters']]], 400, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $messages = $this->bus->dispatch(new FetchMessage(Arr::get($params, 'query', 0), $actor, (int) $chatId));
        } catch (ModelNotFoundException $e) {
            return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found']]], 404, ['Cache-Control' => 'private, no-store']);
        } catch (PermissionDeniedException $e) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403, ['Cache-Control' => 'private, no-store']);
        }

        $data = [];
        $included = [];
        $seenUsers = [];
        $userIds = [];
        foreach ($messages as $message) {
            $data[] = ChatMessageJson::resource($message);
            if (!empty($message->user_id)) {
                $userIds[(int) $message->user_id] = true;
            }
        }
        if ($userIds !== []) {
            foreach (User::query()->whereIn('id', array_keys($userIds))->get() as $user) {
                $doc = ChatMessageJson::includedUser($user, $this->slugManager->forResource(User::class)->toSlug($user));
                if ($doc !== null && !isset($seenUsers[$user->id])) {
                    $included[] = $doc;
                    $seenUsers[$user->id] = true;
                }
            }
        }

        $payload = ['data' => $data];
        if ($included !== []) {
            $payload['included'] = $included;
        }

        return new JsonResponse($payload, 200, ['Cache-Control' => 'private, no-store']);
    }
}
