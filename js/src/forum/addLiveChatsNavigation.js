import { extend } from 'flarum/extend';
import HeaderSecondary from 'flarum/components/HeaderSecondary';
import LinkButton from 'flarum/components/LinkButton';
import LiveChatsPage from './components/LiveChatsPage';
import ChatPage from './components/ChatPage';

/**
 * Direct Messages uses HeaderSecondary priority 5.
 * Live Chats uses priority 4 so it sorts immediately below DM.
 */
export const LIVE_CHATS_HEADER_PRIORITY = 4;
export const DIRECT_MESSAGES_HEADER_PRIORITY = 5;

export default function addLiveChatsNavigation() {
    app.routes['flatrate-live-chat.index'] = {
        path: '/live',
        component: LiveChatsPage,
    };
    app.routes['flatrate-live-chat.live'] = {
        path: '/live/:roomKey',
        component: ChatPage,
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
        if (!app.session.user) return;
        if (!app.forum.attribute('flatrate-live-chat.live_chats_navigation_enabled')) return;
        if (!app.forum.attribute('flatrate-live-chat.permissions.enabled')) return;

        const unread = app.chat && typeof app.chat.getUnreadedTotal === 'function' ? app.chat.getUnreadedTotal() : 0;

        items.add(
            'LiveChats',
            <LinkButton href={app.route('flatrate-live-chat.index')} icon="fas fa-comments" className="FlatRateLiveChatsNav">
                {app.translator.trans('flatrate-live-chat.forum.nav.live_chats')}
                {unread ? <span className="FlatRateLiveChatsNav-badge">{unread}</span> : null}
            </LinkButton>,
            LIVE_CHATS_HEADER_PRIORITY
        );
    });
}
