import Component from 'flarum/Component';
import LoadingIndicator from 'flarum/components/LoadingIndicator';
import ChatHeader from './ChatHeader';
import ChatViewport from './ChatViewport';
import ChatState from '../states/ChatState';
import { roomKeyOf } from '../utils/liveChatPresentation';
import { messagingUiEnabled } from '../utils/messagingUiEnabled';
import { selectRoutedLiveRoom } from '../utils/selectRoutedLiveRoom';

export default class LiveConversationView extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.unavailable = false;
        this.selecting = false;
        this.conversationOwner = {};
        this.selectionGeneration = 0;
        this.ensureChat();
        this.selectRoom(this.attrs.roomKey);
    }

    onremove(vnode) {
        super.onremove(vnode);
        this.selectionGeneration += 1;
        this.releaseRoomView(this.attrs.roomKey || this.lastSelectedKey);
    }

    releaseRoomView(roomKey) {
        if (app.chat && typeof app.chat.releaseActiveConversation === 'function') {
            app.chat.releaseActiveConversation(roomKey, this.conversationOwner);
        }
    }

    holdRoomView(roomKey) {
        if (app.chat && typeof app.chat.holdActiveConversation === 'function') {
            app.chat.holdActiveConversation(roomKey, this.conversationOwner);
        }
    }

    onupdate(vnode) {
        super.onupdate(vnode);
        if (vnode.attrs.roomKey !== this.lastSelectedKey) {
            this.selectRoom(vnode.attrs.roomKey);
        }
    }

    ensureChat() {
        if (!app.chat) {
            app.chat = new ChatState();
        }
    }

    selectRoom(roomKey) {
        selectRoutedLiveRoom(this, roomKey);
    }

    view() {
        const embedded = !!this.attrs.embedded;
        const backToLive = embedded ? false : this.attrs.backToLive !== false;
        let backHref = this.attrs.backHref;
        if (!embedded && messagingUiEnabled() && !backHref) {
            backHref = app.routes && app.routes['flatrate-messaging.index'] ? app.route('flatrate-messaging.index') : '/messages';
        }

        if (this.unavailable) {
            return (
                <div className="LiveConversationView LiveConversationView--unavailable">
                    <p>{app.translator.trans('flatrate-live-chat.forum.live_chats.unavailable')}</p>
                </div>
            );
        }

        const current = app.chat && app.chat.getCurrentChat ? app.chat.getCurrentChat() : null;
        const matches = roomKeyOf(current) === this.attrs.roomKey;
        const loading = this.selecting || app.chat?.chatsLoading || !matches;

        return (
            <div className={'LiveConversationView' + (embedded ? ' LiveConversationView--embedded' : '')}>
                <ChatHeader backToLive={backToLive} backHref={backHref}></ChatHeader>
                {loading ? <LoadingIndicator></LoadingIndicator> : <ChatViewport chatModel={current}></ChatViewport>}
            </div>
        );
    }
}
