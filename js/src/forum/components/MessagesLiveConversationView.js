import Component from 'flarum/Component';
import LoadingIndicator from 'flarum/components/LoadingIndicator';
import ChatViewport from './ChatViewport';
import ChatState from '../states/ChatState';
import { roomKeyOf } from '../utils/liveChatPresentation';
import { selectRoutedLiveRoom } from '../utils/selectRoutedLiveRoom';

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
