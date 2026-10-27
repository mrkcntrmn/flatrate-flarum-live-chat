/**
 * Flarum 2.0.2 forum entry: register Live messaging provider + ChatState boot.
 *
 * Skips Live nav chrome and MAIN/BOARD providers (Messaging UI owns /messages).
 * F1 short imports resolve via webpack F1→F2 aliases + Flarum 2 proxifyCompat.
 */
import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Application from 'flarum/common/Application';
import User from 'flarum/common/models/User';
import Model from 'flarum/common/Model';

import Chat from './models/Chat';
import Message from './models/Message';
import ChatState from './states/ChatState';
import registerLiveMessagingProvider from './registerLiveMessagingProvider';
import FlatRateRealtimeClient from './realtime/FlatRateRealtimeClient';

app.initializers.add('flatrate-live-chat', () => {
    app.store.models.chats = Chat;
    app.store.models.chatmessages = Message;
    // F2 live-chats/rooms endpoints use type `live-rooms` (not `chats`).
    app.store.models['live-rooms'] = Chat;
    // F2 chat message documents use type `chat-messages`.
    app.store.models['chat-messages'] = Message;
    // F2 live-chats/rooms endpoints use type `live-rooms` (not `chats`).
    app.store.models['live-rooms'] = Chat;

    function pivot(name, id, attr, transform) {
        pivot.hasOne = function (name, id, attr) {
            return function () {
                const relationship = this.data.attributes[name] && this.data.attributes[name][id] && this.data.attributes[name][id][attr];
                if (relationship) return app.store.getById(relationship.data.type, relationship.data.id);
            };
        };

        return function () {
            const value = this.data.attributes[name] && this.data.attributes[name][id] && this.data.attributes[name][id][attr];
            return transform ? transform(value) : value;
        };
    }

    Object.assign(User.prototype, {
        chat_pivot(chat_id) {
            return {
                role: pivot('chat_pivot', chat_id, 'role').bind(this),
                removed_by: pivot('chat_pivot', chat_id, 'removed_by').bind(this),
                readed_at: pivot('chat_pivot', chat_id, 'readed_at', Model.transformDate).bind(this),
                removed_at: pivot('chat_pivot', chat_id, 'removed_at', Model.transformDate).bind(this),
                joined_at: pivot('chat_pivot', chat_id, 'joined_at', Model.transformDate).bind(this),
            };
        },
    });

    registerLiveMessagingProvider();

    extend(Application.prototype, 'mount', function () {
        if (!app.forum.attribute('flatrate-live-chat.permissions.enabled')) return;

        // Keep a ChatState created earlier by MessagesLiveConversationView.ensureChat()
        // so in-flight exact-room selection is not orphaned by a remount.
        if (!app.chat) {
            app.chat = new ChatState();
        }

        app.flatrateLiveRealtime = new FlatRateRealtimeClient({
            app,
            onEvent: (envelope) => {
                if (app.chat && typeof app.chat.handleFlatRateRealtime === 'function') {
                    app.chat.handleFlatRateRealtime(envelope);
                }
            },
        });
        if (app.session.user && app.flatrateLiveRealtime.isConfigured()) {
            app.flatrateLiveRealtime.connect();
        }

        if ('Notification' in window && app.chat.getFrameState('notify')) Notification.requestPermission();

        app.chat.apiFetchChats();
    });
});
