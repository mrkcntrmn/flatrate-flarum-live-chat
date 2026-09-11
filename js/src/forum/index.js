import { extend } from 'flarum/extend';
import Application from 'flarum/Application';

import Chat from './models/Chat';
import Message from './models/Message';
import User from 'flarum/models/User';
import Model from 'flarum/Model';
import ChatState from './states/ChatState';
import addLiveChatsNavigation from './addLiveChatsNavigation';
import FlatRateRealtimeClient from './realtime/FlatRateRealtimeClient';

app.initializers.add('flatrate-live-chat', (app) => {
    app.store.models.chats = Chat;
    app.store.models.chatmessages = Message;

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

    addLiveChatsNavigation();

    extend(Application.prototype, 'mount', function () {
        if (!app.forum.attribute('flatrate-live-chat.permissions.enabled')) return;

        app.chat = new ChatState();

        // Owned Centrifugo client — fail closed when not configured.
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
