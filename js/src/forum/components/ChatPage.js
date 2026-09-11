import Page from 'flarum/common/components/Page';
import IndexPage from 'flarum/components/IndexPage';
import LoadingIndicator from 'flarum/components/LoadingIndicator';
import listItems from 'flarum/helpers/listItems';
import ChatHeader from './ChatHeader';
import ChatViewport from './ChatViewport';

export default class ChatPage extends Page {
    oninit(vnode) {
        super.oninit(vnode);

        this.isPhone = app.screen() === 'phone';
        this.bodyClass = this.isPhone ? 'App--live-chat-room' : 'App--chat';

        const roomKey = m.route.param('roomKey');
        if (roomKey && app.chat) {
            const match = (app.chat.chats || []).find((c) => (c.room_key?.() || c.roomKey?.()) === roomKey);
            if (match) {
                app.chat.setCurrentChat(match);
            } else if (typeof app.chat.apiFetchChats === 'function') {
                app.chat.apiFetchChats().then(() => {
                    const found = (app.chat.chats || []).find((c) => (c.room_key?.() || c.roomKey?.()) === roomKey);
                    if (found) app.chat.setCurrentChat(found);
                    m.redraw();
                });
            }
        }
    }

    view() {
        const phone = app.screen() === 'phone';

        if (phone) {
            return (
                <div className="ChatPage ChatPage--fullscreen">
                    <div className="ChatPage-shell">
                        <ChatHeader backToLive={true}></ChatHeader>
                        {app.chat?.chatsLoading ? (
                            <LoadingIndicator></LoadingIndicator>
                        ) : (
                            <ChatViewport chatModel={app.chat.getCurrentChat()}></ChatViewport>
                        )}
                    </div>
                </div>
            );
        }

        const navItems = IndexPage.prototype.sidebarItems();
        if (navItems.has('forumStatisticsWidget')) navItems.remove('forumStatisticsWidget');

        return (
            <div className="ChatPage">
                <nav className="IndexPage-nav sideNav">
                    <ul>{listItems(navItems.toArray())}</ul>
                </nav>
                <div className="ChatPage-main">
                    <ChatHeader backToLive={true}></ChatHeader>
                    {app.chat?.chatsLoading ? (
                        <LoadingIndicator></LoadingIndicator>
                    ) : (
                        <ChatViewport chatModel={app.chat.getCurrentChat()}></ChatViewport>
                    )}
                </div>
            </div>
        );
    }

    onremove(vnode) {
        super.onremove(vnode);
        document.body.classList.remove('App--live-chat-room');
    }
}
