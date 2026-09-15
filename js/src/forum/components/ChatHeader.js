import Component from 'flarum/Component';
import Link from 'flarum/components/Link';
import Dropdown from 'flarum/components/Dropdown';
import extractText from 'flarum/utils/extractText';

import { displayRoomTitle } from '../utils/liveChatPresentation';
import chatHeaderOverflowItems from '../utils/chatHeaderOverflowItems';

export default class ChatHeader extends Component {
    view() {
        const chat = app.chat.getCurrentChat();
        const moreLabel = extractText(app.translator.trans('flatrate-live-chat.forum.toolbar.more'));
        const backLabel = extractText(app.translator.trans('flatrate-live-chat.forum.toolbar.back'));
        const overflow = chat ? chatHeaderOverflowItems(chat).toArray() : [];

        return (
            <div className="ChatHeader">
                {this.attrs.backToLive ? (
                    <Link className="ChatHeader-back" href={this.backHref()} title={backLabel} aria-label={backLabel}>
                        <i className="fas fa-arrow-left"></i>
                    </Link>
                ) : null}
                <h2 className="ChatHeader-title">
                    {chat
                        ? [
                              chat.icon() ? <i class={chat.icon()} style={{ color: chat.color(), 'margin-right': '6px' }}></i> : null,
                              displayRoomTitle(chat),
                          ]
                        : app.translator.trans('flatrate-live-chat.forum.live_chats.title')}
                </h2>
                {chat && overflow.length ? (
                    <Dropdown
                        className="ChatHeader-overflow"
                        buttonClassName="Button Button--icon Button--flat ChatHeader-overflowToggle"
                        menuClassName="Dropdown-menu--right"
                        icon="fas fa-ellipsis-h"
                        caretIcon={null}
                        label={moreLabel}
                        accessibleToggleLabel={moreLabel}
                    >
                        {overflow}
                    </Dropdown>
                ) : null}
            </div>
        );
    }

    backHref() {
        if (this.attrs.backHref) return this.attrs.backHref;
        if (typeof app.route === 'function' && app.routes && app.routes['flatrate-live-chat.index']) {
            return app.route('flatrate-live-chat.index');
        }
        return '/live';
    }
}
