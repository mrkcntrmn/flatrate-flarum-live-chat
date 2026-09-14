import Page from 'flarum/common/components/Page';
import IndexPage from 'flarum/components/IndexPage';
import listItems from 'flarum/helpers/listItems';
import LiveConversationView from './LiveConversationView';

export default class ChatPage extends Page {
    oninit(vnode) {
        super.oninit(vnode);

        this.isPhone = app.screen() === 'phone';
        this.bodyClass = this.isPhone ? 'App--live-chat-room' : 'App--chat';
    }

    view() {
        const phone = app.screen() === 'phone';
        const roomKey = m.route.param('roomKey');
        const conversation = <LiveConversationView roomKey={roomKey} embedded={false} backToLive={true} />;

        if (phone) {
            return (
                <div className="ChatPage ChatPage--fullscreen">
                    <div className="ChatPage-shell">{conversation}</div>
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
                <div className="ChatPage-main">{conversation}</div>
            </div>
        );
    }

    onremove(vnode) {
        super.onremove(vnode);
        document.body.classList.remove('App--live-chat-room');
    }
}
