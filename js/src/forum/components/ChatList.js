import Component from 'flarum/Component';
import ChatPreview from './ChatPreview';
import Link from 'flarum/components/Link';

/**
 * Room list used by Live Chats directory contexts.
 * Floating-frame minimize/pin controls removed.
 */
export default class ChatList extends Component {
    view(vnode) {
        return (
            <div className="ChatList toggled">
                <div className="header">
                    <div className="input-wrapper input--down">
                        <input
                            id="chat-find"
                            bidi={app.chat.q}
                            placeholder={app.translator.trans('flatrate-live-chat.forum.chat.list.placeholder')}
                        />
                    </div>
                </div>
                <div className="list">{this.content()}</div>
            </div>
        );
    }

    content() {
        return app.chat.getChatsSortedByLastUpdate().map((model) => {
            const roomKey = model.room_key?.() || model.roomKey?.();
            return (
                <Link href={roomKey ? app.route('flatrate-live-chat.live', { roomKey }) : '#'} key={model.id()}>
                    <ChatPreview model={model} />
                </Link>
            );
        });
    }
}
