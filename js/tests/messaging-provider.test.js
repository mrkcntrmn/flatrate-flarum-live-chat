import assert from 'assert';
import fs from 'fs';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '../..');

function read(rel) {
  return fs.readFileSync(path.join(root, rel), 'utf8');
}

function makeChat({ roomKey, title, unreaded = 0, createdAt = null, type = 1 } = {}) {
  return {
    type: () => type,
    room_key: () => roomKey,
    title: () => title,
    unreaded: () => unreaded,
    last_message: () =>
      createdAt
        ? {
            created_at: () => createdAt,
            message: () => 'secret body should not be copied',
            content: () => 'secret preview should not be copied',
          }
        : null,
  };
}

async function main() {
  global.app = {
    translator: {
      trans(key) {
        if (key === 'flatrate-live-chat.forum.live_chats.general_label') return 'FlatRate.wiki Live';
        return key;
      },
    },
    forum: {
      attribute(name) {
        if (name === 'apiUrl') return 'https://forum.example/api';
        if (name === 'flatrateMessagingUiEnabled') return false;
        return null;
      },
    },
    routes: {},
    route(name) {
      if (name === 'flatrate-messaging.index') return '/messages';
      return '/' + name;
    },
    chat: { chats: [] },
  };

  const { messagingUiEnabled, liveIndexRedirectHref, liveRoomRedirectHref } = await import(
    pathToFileURL(path.join(__dirname, '../src/forum/utils/messagingUiEnabled.js')).href
  );
  const { normalizeLiveConversation, normalizeLiveDirectory } = await import(
    pathToFileURL(path.join(__dirname, '../src/forum/utils/normalizeLiveConversation.js')).href
  );
  const { createLiveMessagingProvider } = await import(pathToFileURL(path.join(__dirname, '../src/forum/liveMessagingProvider.js')).href);
  const { roomKeyOf } = await import(
    pathToFileURL(path.join(__dirname, '../src/forum/utils/liveChatPresentation.js')).href
  );

  const createdAt = new Date('2026-09-14T15:00:00.000Z');
  const row = normalizeLiveConversation(
    makeChat({ roomKey: 'community-general-live', title: 'General Live', unreaded: 3, createdAt })
  );
  assert.strictEqual(row.id, 'live:community-general-live');
  assert.strictEqual(row.kind, 'live');
  assert.strictEqual(row.key, 'community-general-live');
  assert.strictEqual(row.roomKey, 'community-general-live');
  assert.strictEqual(row.title, 'FlatRate.wiki Live');
  assert.strictEqual(row.activityAt, '2026-09-14T15:00:00.000Z');
  assert.strictEqual(row.unreadCount, 3);
  assert.strictEqual(row.isPublic, true);
  assert.strictEqual(row.userId, null);
  assert.ok(!('body' in row));
  assert.ok(!('preview' in row));
  assert.ok(!('message' in row));
  assert.ok(!Object.keys(row).some((k) => /body|preview|message/i.test(k)));
  console.log('LIVE_ROW_NAMESPACED_NO_BODY=PASS');

  const hiddenCatalogKeys = ['hidden-brand-live', 'staff-only-live', 'neon-secret-live'];
  const listed = normalizeLiveDirectory([makeChat({ roomKey: 'toyota-live', title: 'Toyota Live', unreaded: 1 })]);
  assert.strictEqual(listed.length, 1);
  assert.strictEqual(listed[0].id, 'live:toyota-live');
  for (const key of hiddenCatalogKeys) {
    assert.ok(!listed.some((r) => r.roomKey === key || r.key === key || r.id === 'live:' + key));
  }
  assert.deepStrictEqual(normalizeLiveDirectory([]), []);
  assert.ok(!normalizeLiveDirectory([]).some((r) => r.roomKey === 'community-general-live'));
  console.log('HIDDEN_ROOM_ISOLATION=PASS');

  const payloadRooms = [makeChat({ roomKey: 'community-general-live', title: 'General', unreaded: 2 })];
  const storeModels = [
    ...payloadRooms,
    makeChat({ roomKey: 'hidden-brand-live', title: 'Should not list', unreaded: 9 }),
    makeChat({ roomKey: null, title: 'DM', unreaded: 4, type: 0 }),
  ];
  const mockApp = {
    forum: { attribute: (name) => (name === 'apiUrl' ? 'https://forum.example/api' : null) },
    request: async ({ method, url }) => {
      assert.strictEqual(method, 'GET');
      assert.strictEqual(url, 'https://forum.example/api/flatrate-live-chat/live-chats');
      return { data: payloadRooms };
    },
    store: {
      pushPayload(payload) {
        assert.ok(payload);
        return payloadRooms;
      },
    },
    chat: {
      chats: [
        makeChat({ roomKey: 'community-general-live', title: 'General', unreaded: 2 }),
        makeChat({ roomKey: 'hidden-brand-live', title: 'Hidden', unreaded: 9 }),
        makeChat({ roomKey: '', title: 'DM', unreaded: 11, type: 0 }),
      ],
      apiFetchChats: async () => {},
    },
  };

  const provider = createLiveMessagingProvider({ app: mockApp });
  assert.strictEqual(provider.schemaVersion, 1);
  assert.strictEqual(provider.kind, 'live');
  const conversations = await provider.listConversations();
  assert.strictEqual(conversations.length, 1);
  assert.strictEqual(conversations[0].id, 'live:community-general-live');
  for (const key of hiddenCatalogKeys) {
    assert.ok(!conversations.some((r) => r.roomKey === key));
  }
  assert.ok(!conversations.some((r) => 'body' in r || 'preview' in r));
  assert.strictEqual(provider.getUnreadTotal(), 2);
  mockApp.chat.chats[0] = makeChat({ roomKey: 'community-general-live', title: 'General', unreaded: 0 });
  assert.strictEqual(provider.getUnreadTotal(), 0);
  console.log('LIVE_PROVIDER_PASS=PASS');

  // Route-key overflow resolution must not inherit a stale getCurrentChat().
  const roomA = makeChat({ roomKey: 'room-a-live', title: 'Room A', unreaded: 0 });
  const roomB = makeChat({ roomKey: 'room-b-live', title: 'Room B', unreaded: 0 });
  let currentChat = roomA;
  const overflowApp = {
    chat: {
      chats: [roomA, roomB],
      getCurrentChat: () => currentChat,
    },
  };
  const overflowProvider = createLiveMessagingProvider({
    app: overflowApp,
    buildHeaderOverflowItems(chat) {
      return {
        roomKey: roomKeyOf(chat),
        toArray: () => [{ roomKey: roomKeyOf(chat) }],
      };
    },
  });

  const fromB = overflowProvider.headerOverflowItems({ key: 'room-b-live' });
  assert.ok(fromB, 'requested Live key must resolve even when current chat is another room');
  assert.strictEqual(fromB.roomKey, 'room-b-live');

  const unknown = overflowProvider.headerOverflowItems({ key: 'missing-live' });
  assert.strictEqual(unknown, null, 'unknown route key must not inherit the current room controls');

  currentChat = null;
  const fallback = overflowProvider.headerOverflowItems({});
  assert.strictEqual(fallback, null, 'no key and no current chat yields null');

  currentChat = roomA;
  const currentOnly = overflowProvider.headerOverflowItems({});
  assert.ok(currentOnly);
  assert.strictEqual(currentOnly.roomKey, 'room-a-live');
  console.log('LIVE_HEADER_OVERFLOW_ROUTE_KEY=PASS');

  global.app.forum.attribute = (name) => name === 'flatrateMessagingUiEnabled';
  assert.strictEqual(messagingUiEnabled(), true);
  global.app.forum.attribute = () => false;
  assert.strictEqual(messagingUiEnabled(), false);
  global.app.forum.attribute = () => null;
  assert.strictEqual(messagingUiEnabled(), false);

  global.app.routes = {};
  assert.strictEqual(liveIndexRedirectHref(), '/messages?filter=live');
  assert.strictEqual(liveRoomRedirectHref('toyota-live'), '/messages/live/toyota-live');
  global.app.routes = { 'flatrate-messaging.index': { path: '/messages' } };
  assert.strictEqual(liveIndexRedirectHref(), '/messages?filter=live');

  const nav = read('js/src/forum/addLiveChatsNavigation.js');
  assert.ok(nav.includes('if (messagingUiEnabled()) return;'));
  assert.ok(nav.includes("items.add(\n            'LiveChats'"));
  assert.ok(nav.includes('icon="fas fa-comments"'));
  assert.ok(nav.includes("path: '/live'"));
  assert.ok(nav.includes("path: '/live/:roomKey'"));
  assert.ok(nav.includes('liveIndexRedirectHref()'));
  assert.ok(nav.includes('liveRoomRedirectHref('));
  assert.ok(nav.includes('{ replace: true }'));
  assert.ok(nav.includes('if (messagingUiEnabled())'));
  assert.ok(nav.includes('return m(LiveChatsPage)'));
  assert.ok(nav.includes('return m(ChatPage)'));
  assert.ok(nav.includes('component: RedirectLiveIndex'));
  assert.ok(nav.includes('component: RedirectLiveRoom'));
  assert.ok(!nav.includes('FlatRateLiveChatsNav-badge'));
  assert.ok(!nav.includes('getUnreadedTotal'));

  const index = read('js/src/forum/index.js');
  assert.ok(index.includes("import registerLiveMessagingProvider from './registerLiveMessagingProvider';"));
  assert.ok(index.includes('addLiveChatsNavigation();'));
  assert.ok(index.includes('registerLiveMessagingProvider();'));

  const register = read('js/src/forum/registerLiveMessagingProvider.js');
  assert.ok(register.includes('app.flatRateMessagingSources'));
  assert.ok(register.includes('app.flatRateMessagingSources.live'));
  assert.ok(register.includes('embedded: true'));
  assert.ok(register.includes('backToLive: false'));
  assert.ok(register.includes('LiveConversationView'));
  assert.ok(register.includes('MessagesLiveConversationView'));
  assert.ok(register.includes('presentationVersion === 2'));
  assert.ok(register.includes('buildHeaderOverflowItems'));
  assert.ok(register.includes('chatHeaderOverflowItems'));
  assert.ok(!register.includes('room-catalog.json'));
  assert.ok(!register.includes('resources/room-catalog'));

  const providerSrc = read('js/src/forum/liveMessagingProvider.js');
  assert.ok(providerSrc.includes("url: a.forum.attribute('apiUrl') + '/flatrate-live-chat/live-chats'"));
  assert.ok(providerSrc.includes('pushPayload'));
  assert.ok(providerSrc.includes('normalizeLiveDirectory'));
  assert.ok(providerSrc.includes('headerOverflowItems'));
  assert.ok(providerSrc.includes('buildHeaderOverflowItems'));
  assert.ok(providerSrc.includes('requestedKey'));
  assert.ok(
    /requestedKey && Array\.isArray\(a\.chat\.chats\)/.test(providerSrc),
    'route key must resolve chat before getCurrentChat fallback'
  );
  assert.ok(
    /if \(!requestedKey && typeof a\.chat\.getCurrentChat === 'function'\)/.test(providerSrc),
    'getCurrentChat is only used when no route key is provided'
  );
  assert.ok(!providerSrc.includes('room-catalog.json'));
  assert.ok(!providerSrc.includes('resources/room-catalog'));
  assert.ok(!providerSrc.includes("from './utils/chatHeaderOverflowItems"));

  const chatPage = read('js/src/forum/components/ChatPage.js');
  assert.ok(chatPage.includes('LiveConversationView'));
  assert.ok(chatPage.includes('IndexPage'));
  assert.ok(chatPage.includes('embedded={false}'));

  const view = read('js/src/forum/components/LiveConversationView.js');
  assert.ok(!view.includes('IndexPage'));
  assert.ok(view.includes('ChatHeader'));
  assert.ok(view.includes('ChatViewport'));
  assert.ok(view.includes('embedded'));
  assert.ok(view.includes('live_chats.unavailable'));
  assert.ok(!view.includes('room-catalog.json'));

  const v2 = read('js/src/forum/components/MessagesLiveConversationView.js');
  assert.ok(v2.includes('MessagesLiveSurface'));
  assert.ok(v2.includes('presentationVersion={2}'));
  assert.ok(!v2.includes('ChatHeader'));
  assert.ok(!v2.includes('room-catalog.json'));

  const viewport = read('js/src/forum/components/ChatViewport.js');
  assert.ok(viewport.includes('presentationVersion === 2'));
  assert.ok(viewport.includes('MessagesMessageViewport'));
  assert.ok(viewport.includes('chatWrapper.scrollTop = chatWrapper.scrollHeight'));

  const chatMessage = read('js/src/forum/components/ChatMessage.js');
  assert.ok(chatMessage.includes("'message-wrapper--own'"));
  assert.ok(
    chatMessage.includes("String(this.model.user()?.id()) === String(app.session.user?.id())"),
    'own-message class must compare session user id safely'
  );

  const viewportLess = read('resources/less/forum/ChatViewport.less');
  assert.ok(viewportLess.includes('.ChatViewport--messagesV2'));
  assert.ok(viewportLess.includes('.message-wrapper--own'));
  assert.ok(viewportLess.includes('.avatar-wrapper'));
  assert.ok(viewportLess.includes('position: static'));
  assert.ok(viewportLess.includes('flex-direction: row-reverse'));
  assert.ok(viewportLess.includes('.name'));
  assert.ok(viewportLess.includes('display: none'));
  assert.ok(
    !/message-wrapper--own[\s\S]*?\.avatar-wrapper\s*\{[^}]*display:\s*none/.test(viewportLess),
    'own avatar must remain visible under Messages V2'
  );
  assert.ok(viewportLess.includes('max-width: 82%'));
  assert.ok(viewportLess.includes('max-width: 76%'));
  assert.ok(viewportLess.includes('max-width: 70%'));
  assert.ok(
    viewportLess.includes('color-mix(in srgb, var(--primary-color') &&
      viewportLess.includes('18%') &&
      viewportLess.includes('var(--control-bg'),
    'own bubbles need subtle outgoing background under V2'
  );
  assert.ok(
    viewportLess.includes('.toolbar .right'),
    'own-message V2 CSS must preserve edit/moderation controls'
  );

  const overflowUtil = read('js/src/forum/utils/chatHeaderOverflowItems.js');
  assert.ok(overflowUtil.includes('ChatEditModal'));
  assert.ok(overflowUtil.includes('toggleSound'));
  assert.ok(overflowUtil.includes('toggleNotifications'));
  assert.ok(overflowUtil.includes("'liveSettings'"));
  assert.ok(overflowUtil.includes("'liveSound'"));
  assert.ok(overflowUtil.includes("'liveNotifications'"));

  const chatHeader = read('js/src/forum/components/ChatHeader.js');
  assert.ok(chatHeader.includes('chatHeaderOverflowItems'));
  assert.ok(chatHeader.includes('fa-ellipsis-h'));

  assert.ok(viewportLess.includes('flex-direction: row-reverse'));
  assert.ok(viewportLess.includes('.message-wrapper--own'));
  assert.ok(
    /message-wrapper--own[\s\S]*?> div[\s\S]*?flex-direction:\s*row-reverse/.test(viewportLess),
    'row-reverse must target the inner avatar+bubble row only'
  );
  assert.ok(
    !/message-wrapper--own[\s\S]*?\.avatar-wrapper\s*\{[^}]*display:\s*none/.test(viewportLess),
    'own avatar must remain visible under Messages V2'
  );
  const helper = read('js/src/forum/utils/messagingUiEnabled.js');
  assert.ok(helper.includes("return !!app.forum?.attribute?.('flatrateMessagingUiEnabled');"));

  console.log('MESSAGING_PROVIDER_CONTRACT=PASS');
  console.log('MESSAGING002_LIVE_V2=PASS');
  console.log('MESSAGING003_LIVE_OWN_BUBBLE=PASS');
  console.log('MESSAGING003_LIVE_HEADER_OVERFLOW=PASS');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
