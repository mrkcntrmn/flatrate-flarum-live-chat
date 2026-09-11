<?php
/*
 * This file is part of flatrate/flarum-live-chat
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
use FlatRate\LiveChat\Api\Controllers\RealtimeAuthController;
use FlatRate\LiveChat\Api\Controllers\RealtimeConnectTokenController;
use FlatRate\LiveChat\Api\Controllers\RealtimeEgressProbeController;
use FlatRate\LiveChat\Api\Controllers\RealtimeSubscriptionTokenController;
use FlatRate\LiveChat\Api\Controllers\RoomReconcileController;
use FlatRate\LiveChat\Api\Controllers\RoomReconcilePreviewController;
use FlatRate\LiveChat\Api\Controllers\ListLiveChatsController;
use FlatRate\LiveChat\Api\Controllers\SubscribeRoomController;
use FlatRate\LiveChat\Api\Controllers\UnsubscribeRoomController;
use FlatRate\LiveChat\Console\DoctorCommand;
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/resources/less/forum.less')
        ->js(__DIR__ . '/js/dist/forum.js')
        ->route('/chat', 'chat')
        ->route('/live', 'flatrate-live-chat.index')
        ->route('/live/{roomKey}', 'flatrate-live-chat.live')
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
        ->get('/chat/user/{id}', 'neonchat.chat.user', ShowUserSafeController::class)
        ->post('/flatrate-live-chat/realtime/auth', 'flatrate-live-chat.realtime.auth', RealtimeAuthController::class)
        ->post('/flatrate-live-chat/realtime/connect-token', 'flatrate-live-chat.realtime.connect-token', RealtimeConnectTokenController::class)
        ->post('/flatrate-live-chat/realtime/subscription-token', 'flatrate-live-chat.realtime.subscription-token', RealtimeSubscriptionTokenController::class)
        ->get('/flatrate-live-chat/realtime/egress-probe', 'flatrate-live-chat.realtime.egress-probe', RealtimeEgressProbeController::class)
        ->get('/flatrate-live-chat/admin/rooms/reconcile-preview', 'flatrate-live-chat.admin.rooms.reconcile-preview', RoomReconcilePreviewController::class)
        ->post('/flatrate-live-chat/admin/rooms/reconcile', 'flatrate-live-chat.admin.rooms.reconcile', RoomReconcileController::class)
        ->get('/flatrate-live-chat/live-chats', 'flatrate-live-chat.live-chats', ListLiveChatsController::class)
        ->post('/flatrate-live-chat/rooms/{roomKey}/subscription', 'flatrate-live-chat.rooms.subscribe', SubscribeRoomController::class)
        ->delete('/flatrate-live-chat/rooms/{roomKey}/subscription', 'flatrate-live-chat.rooms.unsubscribe', UnsubscribeRoomController::class),

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
            $nav = resolve(\Flarum\Settings\SettingsRepositoryInterface::class)
                ->get('flatrate-live-chat.live_chats_navigation_enabled');
            $attributes['flatrate-live-chat.live_chats_navigation_enabled'] =
                $nav === '1' || $nav === 1 || $nav === true || $nav === 'true';
            $attributes['flatrate-live-chat.realtime.decision'] = 'CENTRIFUGO_SELF_HOSTED';
            $attributes['flatrate-live-chat.rollout.profile'] = 'general-live-first';
            $attributes['flatrate-live-chat.canPreviewHidden'] = resolve(\FlatRate\LiveChat\Auth\ChatAuthorization::class)
                ->canPreviewHiddenChatRooms($actor);

            $config = resolve(CentrifugoClientConfig::class);
            foreach ($config->forumAttributes() as $k => $v) {
                $attributes[$k] = $v;
            }
            return $attributes;
        }),

    (new Extend\ThrottleApi())
        ->set('chat-message', Api\Throttler\ChatMessage::class),

    (new Extend\Settings())
        ->default('flatrate-live-chat.live_chats_navigation_enabled', '0')
        ->serializeToForum('flatrate-live-chat.settings.charlimit', 'flatrate-live-chat.settings.charlimit')
        ->serializeToForum('flatrate-live-chat.settings.display.minimize', 'flatrate-live-chat.settings.display.minimize')
        ->serializeToForum('flatrate-live-chat.settings.display.censor', 'flatrate-live-chat.settings.display.censor'),

    (new Extend\ServiceProvider())
        ->register(LiveChatServiceProvider::class),

    (new Extend\Event)->subscribe(Listener\PushChatEvents::class),

    (new Extend\Console())
        ->command(DoctorCommand::class),
];
