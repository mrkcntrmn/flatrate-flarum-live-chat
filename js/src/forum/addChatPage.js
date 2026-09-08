import { extend } from 'flarum/extend';
import IndexPage from 'flarum/components/IndexPage';
import LinkButton from 'flarum/components/LinkButton';
import ChatPage from './components/ChatPage';

export default function addChatPage() {
    // Canonical family: /live/{roomKey}
    app.routes['flatrate-live-chat.live'] = {
        path: '/live/:roomKey',
        component: ChatPage,
    };
    // Legacy /chat retained for disposable smoke; not a member nav destination.
    app.routes.chat = { path: '/chat', component: ChatPage };

    extend(IndexPage.prototype, 'navItems', function (items) {
        // SIDEBAR_LIVE_CHAT_TOP_LEVEL=false — do not add a fourth top-level nav item.
        return;
    });
}
