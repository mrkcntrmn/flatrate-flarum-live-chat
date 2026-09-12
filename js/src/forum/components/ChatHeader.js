import Component from 'flarum/Component';
import Link from 'flarum/components/Link';
import Button from 'flarum/components/Button';
import Dropdown from 'flarum/components/Dropdown';
import ItemList from 'flarum/utils/ItemList';
import extractText from 'flarum/utils/extractText';

import ChatEditModal from './ChatEditModal';
import { canAdministerRoom, displayRoomTitle } from '../utils/liveChatPresentation';

export default class ChatHeader extends Component {
    view() {
        const chat = app.chat.getCurrentChat();
        const moreLabel = extractText(app.translator.trans('flatrate-live-chat.forum.toolbar.more'));
        const backLabel = extractText(app.translator.trans('flatrate-live-chat.forum.toolbar.back'));

        return (
            <div className="ChatHeader">
                {this.attrs.backToLive ? (
                    <Link className="ChatHeader-back" href={app.route('flatrate-live-chat.index')} title={backLabel} aria-label={backLabel}>
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
                {chat ? (
                    <Dropdown
                        className="ChatHeader-overflow"
                        buttonClassName="Button Button--icon Button--flat ChatHeader-overflowToggle"
                        menuClassName="Dropdown-menu--right"
                        icon="fas fa-ellipsis-h"
                        caretIcon={null}
                        label={moreLabel}
                        accessibleToggleLabel={moreLabel}
                    >
                        {this.overflowItems(chat).toArray()}
                    </Dropdown>
                ) : null}
            </div>
        );
    }

    overflowItems(chat) {
        const items = new ItemList();

        if (app.session.user) {
            const administer = canAdministerRoom(chat, app.session.user);
            items.add(
                'room',
                <Button icon="fas fa-cog" className="Button" onclick={() => app.modal.show(ChatEditModal, { model: chat })}>
                    {app.translator.trans(
                        administer ? 'flatrate-live-chat.forum.toolbar.chat.settings' : 'flatrate-live-chat.forum.toolbar.chat.info'
                    )}
                </Button>
            );
        }

        items.add(
            'sound',
            <Button
                icon={app.chat.getFrameState('isMuted') ? 'fas fa-volume-mute' : 'fas fa-volume-up'}
                className="Button"
                onclick={this.toggleSound.bind(this)}
            >
                {app.translator.trans('flatrate-live-chat.forum.toolbar.' + (app.chat.getFrameState('isMuted') ? 'enable_sounds' : 'disable_sounds'))}
            </Button>
        );

        items.add(
            'notifications',
            <Button
                icon={app.chat.getFrameState('notify') ? 'fas fa-bell' : 'fas fa-bell-slash'}
                className="Button"
                onclick={this.toggleNotifications.bind(this)}
            >
                {app.translator.trans(
                    'flatrate-live-chat.forum.toolbar.' + (app.chat.getFrameState('notify') ? 'disable_notifications' : 'enable_notifications')
                )}
            </Button>
        );

        return items;
    }

    toggleSound(e) {
        app.chat.toggleSound();
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
    }

    toggleNotifications(e) {
        app.chat.toggleNotifications();
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
    }
}
