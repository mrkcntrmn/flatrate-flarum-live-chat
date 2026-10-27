<?php

/*
 * Flarum 2.0.2 backend for FlatRate Live Chat.
 *
 * Flarum 2 removed the Flarum 1 serializer/JSON:API controller base classes used
 * by historical controllers. This entry keeps server authorization, room routes,
 * Centrifugo token issuance, an F2-safe realtime publisher (no Tobscure), and
 * forum JS that registers app.flatRateMessagingSources.live (Messaging UI owns
 * /messages). Feature defaults stay off. Incomplete Centrifugo config still
 * fails closed via NullRealtimePublisher.
 */

use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Extend;
use Flarum\Frontend\Document;
use Flarum\User\User;
use FlatRate\LiveChat\Api\Controllers\AdminDashboardController;
use FlatRate\LiveChat\Api\Controllers\RealtimeAuthController;
use FlatRate\LiveChat\Api\Controllers\RealtimeConnectTokenController;
use FlatRate\LiveChat\Api\Controllers\RealtimeEgressProbeController;
use FlatRate\LiveChat\Api\Controllers\RealtimePresenceStatsController;
use FlatRate\LiveChat\Api\Controllers\RealtimeSubscriptionTokenController;
use FlatRate\LiveChat\Api\Controllers\RoomReconcileController;
use FlatRate\LiveChat\Api\Controllers\RoomReconcilePreviewController;
use FlatRate\LiveChat\Api\Throttler\ChatMessage;
use FlatRate\LiveChat\Console\DoctorCommand;
use FlatRate\LiveChat\F2\Api\DeleteMessageAction;
use FlatRate\LiveChat\F2\Api\EditMessageAction;
use FlatRate\LiveChat\F2\Api\FetchMessagesAction;
use FlatRate\LiveChat\F2\Api\ListLiveChatsAction;
use FlatRate\LiveChat\F2\Api\PostMessageAction;
use FlatRate\LiveChat\F2\Api\ShowLiveRoomAction;
use FlatRate\LiveChat\F2\Api\SubscribeRoomAction;
use FlatRate\LiveChat\F2\Api\UnsubscribeRoomAction;
use FlatRate\LiveChat\F2\Listener\PushChatEvents as F2PushChatEvents;
use FlatRate\LiveChat\LiveChatServiceProvider;
use FlatRate\LiveChat\Realtime\CentrifugoClientConfig;
use FlatRate\LiveChat\Seo\ChatIndexingPolicy;
use Psr\Http\Message\ServerRequestInterface as Request;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum-f2.js')
        ->css(__DIR__.'/resources/less/forum.less')
        ->route('/chat', 'chat')
        ->route('/live', 'flatrate-live-chat.index')
        ->route('/live/{roomKey}', 'flatrate-live-chat.live')
        ->content(function (Document $document, Request $request) {
            if (!ChatIndexingPolicy::shouldNoIndexPath($request->getUri()->getPath())) {
                return;
            }
            $document->head[] = '<meta name="robots" content="noindex, nofollow">';
        }),

    (new Extend\Locales(__DIR__.'/resources/locale')),

    (new Extend\Routes('api'))
        ->get('/chatmessages', 'neonchat.chatmessages.fetch', FetchMessagesAction::class)
        ->post('/chatmessages/{id}', 'neonchat.chatmessages.post', PostMessageAction::class)
        ->patch('/chatmessages/{id}', 'neonchat.chatmessages.edit', EditMessageAction::class)
        ->delete('/chatmessages/{id}', 'neonchat.chatmessages.delete', DeleteMessageAction::class)
        ->post('/flatrate-live-chat/realtime/auth', 'flatrate-live-chat.realtime.auth', RealtimeAuthController::class)
        ->post('/flatrate-live-chat/realtime/connect-token', 'flatrate-live-chat.realtime.connect-token', RealtimeConnectTokenController::class)
        ->post('/flatrate-live-chat/realtime/subscription-token', 'flatrate-live-chat.realtime.subscription-token', RealtimeSubscriptionTokenController::class)
        ->post('/flatrate-live-chat/realtime/presence-stats', 'flatrate-live-chat.realtime.presence-stats', RealtimePresenceStatsController::class)
        ->get('/flatrate-live-chat/realtime/egress-probe', 'flatrate-live-chat.realtime.egress-probe', RealtimeEgressProbeController::class)
        ->get('/flatrate-live-chat/admin/rooms/reconcile-preview', 'flatrate-live-chat.admin.rooms.reconcile-preview', RoomReconcilePreviewController::class)
        ->get('/flatrate-live-chat/admin/dashboard', 'flatrate-live-chat.admin.dashboard', AdminDashboardController::class)
        ->post('/flatrate-live-chat/admin/rooms/reconcile', 'flatrate-live-chat.admin.rooms.reconcile', RoomReconcileController::class)
        ->get('/flatrate-live-chat/live-chats', 'flatrate-live-chat.live-chats', ListLiveChatsAction::class)
        ->get('/flatrate-live-chat/rooms/{roomKey}', 'flatrate-live-chat.rooms.show', ShowLiveRoomAction::class)
        ->post('/flatrate-live-chat/rooms/{roomKey}/subscription', 'flatrate-live-chat.rooms.subscribe', SubscribeRoomAction::class)
        ->delete('/flatrate-live-chat/rooms/{roomKey}/subscription', 'flatrate-live-chat.rooms.unsubscribe', UnsubscribeRoomAction::class),

    (new Extend\Model(User::class))
        ->relationship('chats', function ($user) {
            return $user->belongsToMany(\FlatRate\LiveChat\Chat::class, 'neonchat_chat_user')
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
            $settings = resolve(\Flarum\Settings\SettingsRepositoryInterface::class);
            $nav = $settings->get('flatrate-live-chat.live_chats_navigation_enabled');
            $attributes['flatrate-live-chat.live_chats_navigation_enabled'] =
                $nav === '1' || $nav === 1 || $nav === true || $nav === 'true';
            $attributes['flatrate-live-chat.general_live_enabled'] =
                \FlatRate\LiveChat\Auth\GeneralLiveGate::isEnabled(
                    $settings->get(\FlatRate\LiveChat\Auth\GeneralLiveGate::SETTING_KEY)
                );
            $mainRollout = new \FlatRate\LiveChat\Auth\GeneralLiveMainRollout($settings);
            $attributes[\FlatRate\LiveChat\Auth\GeneralLiveMainRollout::FORUM_ATTRIBUTE] =
                $mainRollout->availableTo($actor);
            $brandPreview = new \FlatRate\LiveChat\Auth\BrandLiveAdminPreview($settings);
            $attributes[\FlatRate\LiveChat\Auth\BrandLiveAdminPreview::FORUM_ATTRIBUTE] =
                $brandPreview->availableTo($actor);
            $attributes[\FlatRate\LiveChat\Auth\BrandLiveAvailability::FORUM_ATTRIBUTE] =
                (new \FlatRate\LiveChat\Auth\BrandLiveAvailability($settings, $brandPreview))->availableTo($actor);
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
        ->set('chat-message', ChatMessage::class),

    (new Extend\Settings())
        ->default('flatrate-live-chat.admin_dashboard_enabled', '0')
        ->default('flatrate-live-chat.live_chats_navigation_enabled', '0')
        ->default('flatrate-live-chat.general_live_admin_preview_enabled', '0')
        ->default('flatrate-live-chat.general_live_user_enabled', '0')
        ->default('flatrate-live-chat.member_beta_enabled', '0')
        ->serializeToForum('flatrate-live-chat.settings.charlimit', 'flatrate-live-chat.settings.charlimit')
        ->serializeToForum('flatrate-live-chat.settings.display.minimize', 'flatrate-live-chat.settings.display.minimize')
        ->serializeToForum('flatrate-live-chat.settings.display.censor', 'flatrate-live-chat.settings.display.censor'),

    (new Extend\ServiceProvider())
        ->register(LiveChatServiceProvider::class),

    (new Extend\Event())->subscribe(F2PushChatEvents::class),

    (new Extend\Console())
        ->command(DoctorCommand::class),
];
