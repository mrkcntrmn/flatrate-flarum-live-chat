import Button from 'flarum/components/Button';
import ItemList from 'flarum/utils/ItemList';

import ChatEditModal from '../components/ChatEditModal';
import { canAdministerRoom } from './liveChatPresentation';

/**
 * Directory-row overflow for Live conversations.
 * Room Settings/Info only — do not imply per-room semantics for global sound/notify toggles.
 */
export default function chatDirectoryOverflowItems(chat, a = app) {
  const items = new ItemList();
  if (!chat || !a?.session?.user) {
    return items;
  }

  const administer = canAdministerRoom(chat, a.session.user);
  items.add(
    'liveSettings',
    <Button icon="fas fa-cog" className="Button" onclick={() => a.modal.show(ChatEditModal, { model: chat })}>
      {a.translator.trans(
        administer ? 'flatrate-live-chat.forum.toolbar.chat.settings' : 'flatrate-live-chat.forum.toolbar.chat.info'
      )}
    </Button>,
    50
  );

  return items;
}
