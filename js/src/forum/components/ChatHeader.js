import Component from 'flarum/Component';
import ItemList from 'flarum/utils/ItemList';
import Link from 'flarum/components/Link';

import ChatEditModal from './ChatEditModal';

export default class ChatHeader extends Component {
    view(vnode) {
        const attrs = {};

        return (
            <div className="ChatHeader" {...attrs}>
                {this.attrs.backToLive ? (
                    <Link className="icon ChatHeader-back" href={app.route('flatrate-live-chat.index')}>
                        <i className="fas fa-arrow-left"></i>
                    </Link>
                ) : null}
                <h2>
                    {app.chat.getCurrentChat()
                        ? [
                              app.chat.getCurrentChat().icon() ? (
                                  <i
                                      class={app.chat.getCurrentChat().icon()}
                                      style={{ color: app.chat.getCurrentChat().color(), 'margin-right': '3px' }}
                                  ></i>
                              ) : null,
                              this.displayTitle(app.chat.getCurrentChat()),
                          ]
                        : app.translator.trans('flatrate-live-chat.forum.toolbar.title')}
                </h2>
                {!app.chat.getCurrentChat() || !app.session.user ? null : (
                    <div
                        className="icon"
                        data-title={app.translator.trans('flatrate-live-chat.forum.toolbar.chat.settings')}
                        onclick={() => app.modal.show(ChatEditModal, { model: app.chat.getCurrentChat() })}
                    >
                        <i className="fas fa-cog"></i>
                    </div>
                )}
                <div className="window-buttons">{this.windowButtonItems().toArray()}</div>
            </div>
        );
    }

    displayTitle(chat) {
        const key = chat.room_key?.() || chat.roomKey?.();
        if (key === 'community-general-live') {
            return app.translator.trans('flatrate-live-chat.forum.live_chats.general_label');
        }
        return chat.title();
    }

    windowButtonItems() {
        const items = new ItemList();

        items.add(
            'sound',
            <div
                className="icon"
                onclick={this.toggleSound.bind(this)}
                data-title={app.translator.trans(
                    'flatrate-live-chat.forum.toolbar.' + (app.chat.getFrameState('isMuted') ? 'enable_sounds' : 'disable_sounds')
                )}
            >
                <i className={app.chat.getFrameState('isMuted') ? 'fas fa-volume-mute' : 'fas fa-volume-up'}></i>
            </div>
        );

        items.add(
            'notifications',
            <div
                className="icon"
                onclick={this.toggleNotifications.bind(this)}
                data-title={app.translator.trans(
                    'flatrate-live-chat.forum.toolbar.' + (app.chat.getFrameState('notify') ? 'disable_notifications' : 'enable_notifications')
                )}
            >
                <i className={app.chat.getFrameState('notify') ? 'fas fa-bell' : 'fas fa-bell-slash'}></i>
            </div>
        );

        // No minimize / floating pin controls — page navigation owns UX.

        return items;
    }

    toggleSound(e) {
        app.chat.toggleSound();
        e.preventDefault();
        e.stopPropagation();
    }

    toggleNotifications(e) {
        app.chat.toggleNotifications();
        e.preventDefault();
        e.stopPropagation();
    }
}
