import Component from 'flarum/Component';
import LoadingIndicator from 'flarum/components/LoadingIndicator';
import ChatViewport from './ChatViewport';
import ChatState from '../states/ChatState';
import { roomKeyOf } from '../utils/liveChatPresentation';

function findChatByRoomKey(roomKey) {
    if (!roomKey || !app.chat || !Array.isArray(app.chat.chats)) return null;
    return app.chat.chats.find((c) => roomKeyOf(c) === roomKey) || null;
}

/**
 * Messages V2 Live surface. Shell owns the conversation header.
 * Reuses ChatViewport + existing Live fetch/realtime/unread mechanics.
 */
export default class MessagesLiveConversationView extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.unavailable = false;
        this.selecting = false;
        this.conversationOwner = {};
        this.ensureChat();
        this.selectRoom(this.attrs.roomKey);
    }

    onremove(vnode) {
        super.onremove(vnode);
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
                if (this.lastSelectedKey !== roomKey) return;
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
        if (this.unavailable) {
            return (
                <div className="MessagesLiveSurface MessagesLiveSurface--unavailable">
                    <p>{app.translator.trans('flatrate-live-chat.forum.live_chats.unavailable')}</p>
                </div>
            );
        }

        const current = app.chat && app.chat.getCurrentChat ? app.chat.getCurrentChat() : null;
        const matches = roomKeyOf(current) === this.attrs.roomKey;
        const loading = this.selecting || app.chat?.chatsLoading || !matches;

        return (
            <div className="MessagesLiveSurface">
                {loading ? (
                    <div className="MessagesLiveSurface-loading">
                        <LoadingIndicator />
                    </div>
                ) : (
                    <ChatViewport chatModel={current} presentationVersion={2} />
                )}
            </div>
        );
    }
}
