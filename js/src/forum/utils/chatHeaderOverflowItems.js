import Button from 'flarum/components/Button';
import ItemList from 'flarum/utils/ItemList';

import ChatEditModal from '../components/ChatEditModal';
import { canAdministerRoom } from './liveChatPresentation';

/**
 * Shared overflow actions for legacy ChatHeader and Messages shell header.
 */
export default function chatHeaderOverflowItems(chat, a = app) {
  const items = new ItemList();
  if (!chat || !a?.chat) {
    return items;
  }

  if (a.session.user) {
    const administer = canAdministerRoom(chat, a.session.user);
    items.add(
      'liveSettings',
      <Button icon="fas fa-cog" className="Button" onclick={() => a.modal.show(ChatEditModal, { model: chat })}>
        {a.translator.trans(
          administer ? 'flatrate-live-chat.forum.toolbar.chat.settings' : 'flatrate-live-chat.forum.toolbar.chat.info'
        )}
      </Button>
    );
  }

  items.add(
    'liveSound',
    <Button
      icon={a.chat.getFrameState('isMuted') ? 'fas fa-volume-mute' : 'fas fa-volume-up'}
      className="Button"
      onclick={(e) => {
        a.chat.toggleSound();
        e?.preventDefault?.();
        e?.stopPropagation?.();
      }}
    >
      {a.translator.trans(
        'flatrate-live-chat.forum.toolbar.' + (a.chat.getFrameState('isMuted') ? 'enable_sounds' : 'disable_sounds')
      )}
    </Button>
  );

  items.add(
    'liveNotifications',
    <Button
      icon={a.chat.getFrameState('notify') ? 'fas fa-bell' : 'fas fa-bell-slash'}
      className="Button"
      onclick={(e) => {
        a.chat.toggleNotifications();
        e?.preventDefault?.();
        e?.stopPropagation?.();
      }}
    >
      {a.translator.trans(
        'flatrate-live-chat.forum.toolbar.' +
          (a.chat.getFrameState('notify') ? 'disable_notifications' : 'enable_notifications')
      )}
    </Button>
  );

  return items;
}
