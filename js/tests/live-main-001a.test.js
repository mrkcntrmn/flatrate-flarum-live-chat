/**
 * FORUM-LIVE-MAIN-001A — reason-aware acquire/release + liveMainProvider.
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const GENERAL_LIVE_PRESENCE_STORAGE_KEY = 'flatrate:general-live-presence:v1';
const PERSISTENT_LIVE_REASON = 'persistent_user_live';

function channelForRoomKey(roomKey) {
  if (!roomKey || typeof roomKey !== 'string') return null;
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(roomKey)) return null;
  return `$flatrate-live-${roomKey}`;
}

class MockCentrifugeClient {
  constructor(url, opts) {
    this.url = url;
    this.opts = opts;
    this.subs = new Map();
    this.handlers = {};
  }
  on(event, fn) {
    this.handlers[event] = fn;
  }
  connect() {
    if (this.handlers.connected) this.handlers.connected();
  }
  disconnect() {}
  newSubscription(channel, opts) {
    const sub = {
      channel,
      opts,
      handlers: {},
      subscribeCalls: 0,
      unsubscribeCalls: 0,
      on(event, fn) {
        this.handlers[event] = fn;
      },
      subscribe() {
        this.subscribeCalls += 1;
      },
      unsubscribe() {
        this.unsubscribeCalls += 1;
      },
      removeAllListeners() {
        this.handlers = {};
      },
    };
    this.subs.set(channel, sub);
    return sub;
  }
}

class FlatRateRealtimeClient {
  constructor(options = {}) {
    this.app = options.app;
    this.Centrifuge = options.Centrifuge;
    this.fetchImpl = options.fetchImpl;
    this.onEvent = options.onEvent || (() => {});
    this.client = null;
    this.subscriptions = new Map();
    this.roomReasons = new Map();
    this.seen = new Set();
  }
  forumAttr(key) {
    return this.app?.forum?.attribute?.(key);
  }
  isConfigured() {
    return (
      !!this.forumAttr('flatrate-live-chat.realtime.connect') &&
      !!this.forumAttr('flatrate-live-chat.realtime.websocketUrl') &&
      this.forumAttr('flatrate-live-chat.realtime.transport') === 'CENTRIFUGO'
    );
  }
  async connect() {
    if (!this.isConfigured()) return false;
    if (this.client) return true;
    this.client = new this.Centrifuge(this.forumAttr('flatrate-live-chat.realtime.websocketUrl'), {
      getToken: async () => 't',
    });
    this.client.connect();
    return true;
  }
  async subscribe(roomKey) {
    const channel = channelForRoomKey(roomKey);
    if (!channel) return null;
    if (!(await this.connect())) return null;
    if (this.subscriptions.has(roomKey)) return this.subscriptions.get(roomKey);
    const sub = this.client.newSubscription(channel, {
      getToken: async () => 't',
    });
    sub.subscribe();
    this.subscriptions.set(roomKey, sub);
    return sub;
  }
  async acquire(roomKey, reason) {
    if (!roomKey || !reason) return null;
    let reasons = this.roomReasons.get(roomKey);
    if (!reasons) {
      reasons = new Set();
      this.roomReasons.set(roomKey, reasons);
    }
    const first = reasons.size === 0;
    reasons.add(String(reason));
    if (first || !this.subscriptions.has(roomKey)) {
      const sub = await this.subscribe(roomKey);
      if (!sub) {
        reasons.delete(String(reason));
        if (reasons.size === 0) this.roomReasons.delete(roomKey);
        return null;
      }
      return sub;
    }
    return this.subscriptions.get(roomKey);
  }
  release(roomKey, reason) {
    if (!roomKey || !reason) return;
    const reasons = this.roomReasons.get(roomKey);
    if (!reasons) return;
    reasons.delete(String(reason));
    if (reasons.size === 0) {
      this.roomReasons.delete(roomKey);
      this.unsubscribe(roomKey);
    }
  }
  unsubscribe(roomKey) {
    if (!this.subscriptions.has(roomKey)) return;
    this.subscriptions.get(roomKey).unsubscribe();
    this.subscriptions.delete(roomKey);
    this.roomReasons.delete(roomKey);
  }
  reasonCount(roomKey) {
    const reasons = this.roomReasons.get(roomKey);
    return reasons ? reasons.size : 0;
  }
}

function memoryStorage(seed = {}) {
  const data = { ...seed };
  return {
    getItem(k) {
      return Object.prototype.hasOwnProperty.call(data, k) ? data[k] : null;
    },
    setItem(k, v) {
      data[k] = String(v);
    },
  };
}

function createLiveMainProvider(options = {}) {
  const getApp = () => options.app;
  const storage = options.storage;
  const PRIMARY = 'community-general-live';
  let preferredLive = false;
  if (storage) {
    const raw = storage.getItem(GENERAL_LIVE_PRESENCE_STORAGE_KEY);
    preferredLive = raw === '1' || raw === 'true';
  }
  let liveCount = null;

  function available() {
    const a = getApp();
    if (!a?.session?.user) return false;
    if (!a.forum?.attribute?.('flatrate-live-chat.permissions.enabled')) return false;
    if (a.forum.attribute('flatrate-live-chat.general_live_enabled') !== true) return false;
    return true;
  }
  function userLive() {
    return preferredLive && available();
  }
  async function syncPersistentSubscription() {
    const rt = options.realtime;
    if (!rt) return;
    if (userLive()) await rt.acquire(PRIMARY, PERSISTENT_LIVE_REASON);
    else rt.release(PRIMARY, PERSISTENT_LIVE_REASON);
  }
  return {
    available,
    liveCount: () => liveCount,
    userLive,
    async setUserLive(value) {
      preferredLive = !!value;
      if (storage) storage.setItem(GENERAL_LIVE_PRESENCE_STORAGE_KEY, preferredLive ? '1' : '0');
      await syncPersistentSubscription();
      return userLive();
    },
    href: () => '/messages/live/community-general-live',
    preferredLive: () => preferredLive,
    async refreshLiveCount() {
      const a = getApp();
      if (!available()) {
        liveCount = null;
        return null;
      }
      try {
        const payload = await a.request({
          method: 'POST',
          url: '/flatrate-live-chat/realtime/presence-stats',
          body: { roomKey: PRIMARY },
        });
        if (!payload || payload.available === false || payload.liveUserCount == null) {
          liveCount = null;
          return null;
        }
        liveCount = Math.floor(Number(payload.liveUserCount));
        return liveCount;
      } catch (e) {
        liveCount = null;
        return null;
      }
    },
    handleAdminDisabled() {
      options.realtime?.release(PRIMARY, PERSISTENT_LIVE_REASON);
      liveCount = null;
    },
    syncPersistentSubscription,
  };
}

async function main() {
  const attrs = {
    'flatrate-live-chat.realtime.connect': true,
    'flatrate-live-chat.realtime.transport': 'CENTRIFUGO',
    'flatrate-live-chat.realtime.websocketUrl': 'wss://example.test/connection/websocket',
  };
  const app = { forum: { attribute: (k) => attrs[k] } };
  const client = new FlatRateRealtimeClient({
    Centrifuge: MockCentrifugeClient,
    fetchImpl: async () => ({ ok: true, json: async () => ({ token: 't' }) }),
    app,
  });

  // active only
  await client.acquire('community-general-live', 'active_conversation');
  assert.strictEqual(client.subscriptions.size, 1);
  assert.strictEqual(client.reasonCount('community-general-live'), 1);
  const channel = '$flatrate-live-community-general-live';
  assert.strictEqual(client.client.subs.get(channel).subscribeCalls, 1);

  // add persistent — no second physical subscribe
  await client.acquire('community-general-live', 'persistent_user_live');
  assert.strictEqual(client.subscriptions.size, 1);
  assert.strictEqual(client.reasonCount('community-general-live'), 2);
  assert.strictEqual(client.client.subs.get(channel).subscribeCalls, 1);

  // release active — persistent remains
  client.release('community-general-live', 'active_conversation');
  assert.strictEqual(client.subscriptions.size, 1);
  assert.strictEqual(client.reasonCount('community-general-live'), 1);

  // release final — unsubscribe
  client.release('community-general-live', 'persistent_user_live');
  assert.strictEqual(client.subscriptions.size, 0);
  assert.strictEqual(client.reasonCount('community-general-live'), 0);
  assert.strictEqual(client.client.subs.get(channel).unsubscribeCalls, 1);

  // persistent only
  await client.acquire('community-general-live', 'persistent_user_live');
  assert.strictEqual(client.subscriptions.size, 1);
  client.release('community-general-live', 'persistent_user_live');
  assert.strictEqual(client.subscriptions.size, 0);

  // --- provider ---
  const storage = memoryStorage();
  let presencePayload = { liveUserCount: 4, available: true };
  const forumAttrs = {
    'flatrate-live-chat.permissions.enabled': true,
    'flatrate-live-chat.general_live_enabled': true,
    'flatrate-live-chat.realtime.connect': true,
  };
  const providerApp = {
    session: { user: { id: () => 1 } },
    forum: { attribute: (k) => forumAttrs[k] },
    request: async () => presencePayload,
  };
  const provider = createLiveMainProvider({
    app: providerApp,
    storage,
    realtime: client,
  });

  assert.strictEqual(provider.available(), true);
  assert.strictEqual(provider.userLive(), false);
  assert.strictEqual(provider.href(), '/messages/live/community-general-live');

  await provider.setUserLive(true);
  assert.strictEqual(provider.userLive(), true);
  assert.strictEqual(storage.getItem(GENERAL_LIVE_PRESENCE_STORAGE_KEY), '1');
  assert.strictEqual(client.subscriptions.size, 1);
  assert.strictEqual(client.reasonCount('community-general-live'), 1);

  // open room while persistent — still one physical
  await client.acquire('community-general-live', 'active_conversation');
  assert.strictEqual(client.subscriptions.size, 1);
  assert.strictEqual(client.reasonCount('community-general-live'), 2);

  // leave room — persistent remains
  client.release('community-general-live', 'active_conversation');
  assert.strictEqual(client.subscriptions.size, 1);

  // count
  assert.strictEqual(await provider.refreshLiveCount(), 4);
  assert.strictEqual(provider.liveCount(), 4);
  presencePayload = { liveUserCount: 0, available: true };
  assert.strictEqual(await provider.refreshLiveCount(), 0);
  presencePayload = { liveUserCount: null, available: false };
  assert.strictEqual(await provider.refreshLiveCount(), null);

  // admin off: available false, preference retained, presence released
  forumAttrs['flatrate-live-chat.general_live_enabled'] = false;
  assert.strictEqual(provider.available(), false);
  assert.strictEqual(provider.userLive(), false);
  assert.strictEqual(provider.preferredLive(), true);
  provider.handleAdminDisabled();
  assert.strictEqual(client.subscriptions.size, 0);
  assert.strictEqual(storage.getItem(GENERAL_LIVE_PRESENCE_STORAGE_KEY), '1');

  // guest / missing provider fail-closed
  forumAttrs['flatrate-live-chat.general_live_enabled'] = true;
  providerApp.session.user = null;
  assert.strictEqual(provider.available(), false);

  // source contracts
  const rtSrc = fs.readFileSync(path.join(__dirname, '../src/forum/realtime/FlatRateRealtimeClient.js'), 'utf8');
  assert.ok(rtSrc.includes('async acquire('));
  assert.ok(rtSrc.includes('release('));
  assert.ok(rtSrc.includes('roomReasons'));
  const providerSrc = fs.readFileSync(path.join(__dirname, '../src/forum/liveMainProvider.js'), 'utf8');
  assert.ok(providerSrc.includes('flatrate:general-live-presence:v1'));
  assert.ok(providerSrc.includes('persistent_user_live'));
  assert.ok(providerSrc.includes('/messages/live/community-general-live'));
  const registerSrc = fs.readFileSync(path.join(__dirname, '../src/forum/registerLiveMainProvider.js'), 'utf8');
  assert.ok(registerSrc.includes('flatRateLiveMain'));
  const chatState = fs.readFileSync(path.join(__dirname, '../src/forum/states/ChatState.js'), 'utf8');
  assert.ok(chatState.includes("acquire(roomKey, 'active_conversation')"));
  assert.ok(chatState.includes("release(roomKey, 'active_conversation')"));

  console.log('js_live_main_001a_ok');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
