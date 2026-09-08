<?php
/*
 * This file is part of flatrate/flarum-live-chat
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlatRate\LiveChat;

use Flarum\Extend;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Frontend\Document;
use Flarum\User\User;
use FlatRate\LiveChat\Api\Controllers\PostMessageController;
use FlatRate\LiveChat\Api\Controllers\FetchMessageController;
use FlatRate\LiveChat\Api\Controllers\EditMessageController;
use FlatRate\LiveChat\Api\Controllers\DeleteMessageController;
use FlatRate\LiveChat\Api\Controllers\ShowUserSafeController;
use FlatRate\LiveChat\Api\Controllers\ListChatsController;
use FlatRate\LiveChat\Api\Controllers\CreateChatController;
use FlatRate\LiveChat\Api\Controllers\EditChatController;
use FlatRate\LiveChat\Api\Controllers\DeleteChatController;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/resources/less/forum.less')
        ->js(__DIR__ . '/js/dist/forum.js')
        ->route('/chat', 'chat')
        ->content(function (Document $document) {
            // CHAT_INDEXING=false
            $document->head[] = '<meta name="robots" content="noindex, nofollow">';
        }),

    (new Extend\Locales(__DIR__ . '/resources/locale')),

    (new Extend\Routes('api'))
        ->get('/chats', 'neonchat.chats.get', ListChatsController::class)
        ->post('/chats', 'neonchat.chats.create', CreateChatController::class)
        ->patch('/chats/{id}', 'neonchat.chats.edit', EditChatController::class)
        ->delete('/chats/{id}', 'neonchat.chats.delete', DeleteChatController::class)
        ->get('/chatmessages', 'neonchat.chatmessages.fetch', FetchMessageController::class)
        ->post('/chatmessages/{id}', 'neonchat.chatmessages.post', PostMessageController::class)
        ->patch('/chatmessages/{id}', 'neonchat.chatmessages.edit', EditMessageController::class)
        ->delete('/chatmessages/{id}', 'neonchat.chatmessages.delete', DeleteMessageController::class)
        ->get('/chat/user/{id}', 'neonchat.chat.user', ShowUserSafeController::class),

    (new Extend\Model(User::class))
        ->relationship('chats', function ($user) {
            return $user->belongsToMany(Chat::class, 'neonchat_chat_user')
                ->withPivot('joined_at', 'removed_by', 'role', 'readed_at', 'removed_at');
        }),

    (new Extend\ApiSerializer(ForumSerializer::class))
        ->attributes(function ($serializer, $model, $attributes) {
            $actor = $serializer->getActor();
            $permissions = [
                'flatrate-live-chat.permissions.chat',
                'flatrate-live-chat.permissions.create',
                'flatrate-live-chat.permissions.create.channel',
                'flatrate-live-chat.permissions.enabled',
                'flatrate-live-chat.permissions.edit',
                'flatrate-live-chat.permissions.delete',
                'flatrate-live-chat.permissions.moderate',
                'flatrate-live-chat.permissions.admin-rooms',
            ];
            foreach ($permissions as $permission) {
                $attributes[$permission] = $actor->can($permission);
            }
            $attributes['flatrate-live-chat.settings.attachments'] = false;
            $attributes['flatrate-live-chat.settings.email_notifications'] = false;
            $attributes['flatrate-live-chat.settings.indexing'] = false;
            $attributes['flatrate-live-chat.realtime.decision'] = 'PENDING';
            return $attributes;
        }),

    (new Extend\ThrottleApi())
        ->set('chat-message', Api\Throttler\ChatMessage::class),

    (new Extend\Settings())
        ->serializeToForum('flatrate-live-chat.settings.charlimit', 'flatrate-live-chat.settings.charlimit')
        ->serializeToForum('flatrate-live-chat.settings.display.minimize', 'flatrate-live-chat.settings.display.minimize')
        ->serializeToForum('flatrate-live-chat.settings.display.censor', 'flatrate-live-chat.settings.display.censor'),

    (new Extend\ServiceProvider())
        ->register(LiveChatServiceProvider::class),

    (new Extend\Event)->subscribe(Listener\PushChatEvents::class),
];
