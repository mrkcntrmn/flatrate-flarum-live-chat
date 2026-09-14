import { extend } from 'flarum/extend';
import HeaderSecondary from 'flarum/components/HeaderSecondary';
import LinkButton from 'flarum/components/LinkButton';
import LiveChatsPage from './components/LiveChatsPage';
import ChatPage from './components/ChatPage';
import { liveIndexRedirectHref, liveRoomRedirectHref, messagingUiEnabled } from './utils/messagingUiEnabled';

/**
 * Direct Messages uses HeaderSecondary priority 5.
 * Live Chat uses priority 4 so it sorts immediately below DM.
 */
export const LIVE_CHATS_HEADER_PRIORITY = 4;
export const DIRECT_MESSAGES_HEADER_PRIORITY = 5;

const RedirectLiveIndex = {
    oninit() {
        if (messagingUiEnabled()) {
            m.route.set(liveIndexRedirectHref(), null, { replace: true });
        }
    },
    view() {
        if (messagingUiEnabled()) {
            return null;
        }
        return m(LiveChatsPage);
    },
};

const RedirectLiveRoom = {
    oninit() {
        if (messagingUiEnabled()) {
            m.route.set(liveRoomRedirectHref(m.route.param('roomKey')), null, { replace: true });
        }
    },
    view() {
        if (messagingUiEnabled()) {
            return null;
        }
        return m(ChatPage);
    },
};

export default function addLiveChatsNavigation() {
    app.routes['flatrate-live-chat.index'] = {
        path: '/live',
        component: RedirectLiveIndex,
    };
    app.routes['flatrate-live-chat.live'] = {
        path: '/live/:roomKey',
        component: RedirectLiveRoom,
    };
    // Legacy /chat → /live for disposable smoke / bookmarks.
    app.routes.chat = {
        path: '/chat',
        component: {
            oninit() {
                m.route.set(app.route('flatrate-live-chat.index'));
            },
            view() {
                return null;
            },
        },
    };

    extend(HeaderSecondary.prototype, 'items', function (items) {
        if (messagingUiEnabled()) return;
        if (!app.session.user) return;
        if (!app.forum.attribute('flatrate-live-chat.live_chats_navigation_enabled')) return;
        if (!app.forum.attribute('flatrate-live-chat.permissions.enabled')) return;

        items.add(
            'LiveChats',
            <LinkButton href={app.route('flatrate-live-chat.index')} icon="fas fa-comments" className="FlatRateLiveChatsNav">
                {app.translator.trans('flatrate-live-chat.forum.nav.live_chats')}
            </LinkButton>,
            LIVE_CHATS_HEADER_PRIORITY
        );
    });
}
