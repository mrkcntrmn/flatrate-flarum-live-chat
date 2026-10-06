import Component from 'flarum/Component';
import LoadingIndicator from 'flarum/components/LoadingIndicator';
import ChatHeader from './ChatHeader';
import ChatViewport from './ChatViewport';
import ChatState from '../states/ChatState';
import { roomKeyOf } from '../utils/liveChatPresentation';
import { messagingUiEnabled } from '../utils/messagingUiEnabled';

function findChatByRoomKey(roomKey) {
    if (!roomKey || !app.chat || !Array.isArray(app.chat.chats)) return null;
    return app.chat.chats.find((c) => roomKeyOf(c) === roomKey) || null;
}

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
        this.ensureChat();
        const selectionGeneration = ++this.selectionGeneration;
        if (this.lastSelectedKey !== roomKey) {
            this.releaseRoomView(this.lastSelectedKey);
        }
        this.lastSelectedKey = roomKey;

        if (!roomKey) {
            this.unavailable = true;
            this.selecting = false;
            this.releaseRoomView();
            return;
        }

        const match = findChatByRoomKey(roomKey);
        if (match) {
            this.unavailable = false;
            this.selecting = false;
            app.chat.setCurrentChat(match);
            this.holdRoomView(roomKey);
            return;
        }

        if (typeof app.chat.apiFetchChats === 'function') {
            this.selecting = true;
            this.unavailable = false;
            app.chat.apiFetchChats().then(() => {
                if (selectionGeneration !== this.selectionGeneration || this.lastSelectedKey !== roomKey) return;
                const found = findChatByRoomKey(roomKey);
                if (found) {
                    this.unavailable = false;
                    app.chat.setCurrentChat(found);
                    this.holdRoomView(roomKey);
                } else {
                    this.unavailable = true;
                    this.releaseRoomView(roomKey);
                    if (app.chat.getCurrentChat && roomKeyOf(app.chat.getCurrentChat()) !== roomKey) {
                        app.chat.setCurrentChat(null);
                    }
                }
                this.selecting = false;
                m.redraw();
            });
            return;
        }

        this.unavailable = true;
        this.selecting = false;
        this.releaseRoomView(roomKey);
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
