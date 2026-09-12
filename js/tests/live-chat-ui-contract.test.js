import assert from 'assert';
import fs from 'fs';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '../..');

async function loadPresentation() {
  return import(pathToFileURL(path.join(__dirname, '../src/forum/utils/liveChatPresentation.js')).href);
}

function read(rel) {
  return fs.readFileSync(path.join(root, rel), 'utf8');
}

async function main() {
  const { displayRoomTitle, formatDirectoryTime, orderLiveDirectoryRooms, PRIMARY_ROOM_KEY } = await loadPresentation();

  const translator = {
    trans(key) {
      if (key === 'flatrate-live-chat.forum.live_chats.general_label') return 'FlatRate.wiki Live';
      return key;
    },
  };

  assert.strictEqual(PRIMARY_ROOM_KEY, 'community-general-live');
  assert.strictEqual(
    displayRoomTitle({ room_key: () => 'community-general-live', title: () => 'General Live' }, translator),
    'FlatRate.wiki Live'
  );
  assert.strictEqual(displayRoomTitle({ room_key: () => 'toyota-live', title: () => 'Toyota Live' }, translator), 'Toyota Live');

  const ordered = orderLiveDirectoryRooms([
    { room_key: () => 'toyota-live', title: () => 'Toyota Live' },
    { room_key: () => 'community-general-live', title: () => 'General' },
    { room_key: () => 'ford-live', title: () => 'Ford Live' },
  ]);
  assert.deepStrictEqual(
    ordered.map((c) => c.room_key()),
    ['community-general-live', 'toyota-live', 'ford-live']
  );

  const now = new Date('2026-09-12T15:00:00');
  assert.strictEqual(formatDirectoryTime(new Date('2026-09-12T14:58:00'), now), '2m');
  assert.strictEqual(formatDirectoryTime(new Date('2026-09-12T07:00:00'), now), '8h');
  assert.strictEqual(formatDirectoryTime(new Date('2026-09-11T15:00:00'), now), 'Yesterday');
  assert.match(formatDirectoryTime(new Date('2026-09-10T15:00:00'), now), /Sep/);

  const en = read('resources/locale/en.yaml');
  assert.match(en, /^flatrate-live-chat:/m);
  assert.ok(en.includes('live_chats: Live Chat'));
  assert.ok(en.includes('title: Live Chat'));
  assert.ok(en.includes('general_label: FlatRate.wiki Live'));
  assert.ok(en.includes("empty: Live Chat isn't available yet."));
  assert.ok(en.includes('new_messages: New messages'));
  assert.ok(en.includes('jump_latest: Jump to latest'));
  assert.ok(!en.includes('Live Chats'));
  assert.ok(!en.includes('FlatRate.wiki General'));
  assert.ok(!en.includes('Welcome to Neon Chat!'));
  assert.ok(!en.includes('Show Live Chats navigation'));

  const page = read('js/src/forum/components/LiveChatsPage.js');
  assert.ok(page.includes('displayRoomTitle'));
  assert.ok(page.includes('orderLiveDirectoryRooms'));
  assert.ok(page.includes('formatDirectoryTime'));
  assert.ok(page.includes('live_chats.empty'));
  assert.ok(!page.includes('LiveChatsPage-unread'));
  assert.ok(!page.includes('scope_key'));
  assert.ok(!page.includes('general_heading'));
  assert.ok(!page.includes('subscriptions_heading'));
  assert.ok(!page.includes('Community'));
  assert.ok(!page.includes('Your Live Chats'));
  assert.ok(!page.includes('Your Rooms'));

  const nav = read('js/src/forum/addLiveChatsNavigation.js');
  assert.ok(!nav.includes('FlatRateLiveChatsNav-badge'));
  assert.ok(!nav.includes('getUnreadedTotal'));
  assert.ok(nav.includes("icon=\"fas fa-comments\""));

  const header = read('js/src/forum/components/ChatHeader.js');
  assert.ok(header.includes('displayRoomTitle'));
  assert.ok(header.includes('ChatHeader-overflow'));
  assert.ok(header.includes('toolbar.chat.info'));
  assert.ok(!header.includes('window-buttons'));

  const input = read('js/src/forum/components/ChatInput.js');
  assert.ok(input.includes('ChatInput-send'));
  assert.ok(input.includes('fa-paper-plane'));
  assert.ok(input.includes('<Button'));
  assert.ok(!input.includes('fa-angle-double-right'));
  assert.ok(input.includes('remaining < 100'));

  const viewport = read('js/src/forum/components/ChatViewport.js');
  assert.ok(viewport.includes('live_chats.new_messages'));
  assert.ok(viewport.includes('live_chats.jump_latest'));
  assert.ok(viewport.includes('hasNewMessageState'));
  assert.ok(viewport.includes('processVisibleUnread'));
  assert.ok(viewport.includes('apiReadChat'));
  assert.ok(!viewport.includes('37 new'));
  assert.ok(!viewport.includes('99+'));

  const less = read('resources/less/forum/ChatPage.less') + read('resources/less/forum/ChatHeader.less');
  assert.ok(!less.includes('LiveChatsPage-unread'));
  assert.ok(!less.includes('FlatRateLiveChatsNav-badge'));
  assert.ok(!less.includes('max-width: 150px'));

  console.log('LIVE_CHAT_UI_CONTRACT=PASS');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
