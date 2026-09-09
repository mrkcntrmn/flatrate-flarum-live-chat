const assert = require('assert');

function eventDedupeKey(envelope) {
  if (!envelope || typeof envelope !== 'object') return null;
  const order = envelope.order ?? envelope.payload?.order ?? envelope.payload?.messageId;
  return [envelope.type, envelope.roomKey, order].join('|');
}

function channelForRoomKey(roomKey) {
  if (!roomKey || typeof roomKey !== 'string') return null;
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(roomKey)) return null;
  return `$flatrate-live-${roomKey}`;
}

function roomKeyFromChannel(channel) {
  if (!channel || typeof channel !== 'string') return null;
  if (!channel.startsWith('$flatrate-live-')) return null;
  const roomKey = channel.slice('$flatrate-live-'.length);
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(roomKey)) return null;
  return roomKey;
}

// Minimal in-process mock of FlatRateRealtimeClient behavior (no network).
class MockCentrifugeClient {
  constructor(url, opts) {
    this.url = url;
    this.opts = opts;
    this.subs = new Map();
    this.connected = false;
    this.handlers = {};
  }
  on(event, fn) {
    this.handlers[event] = fn;
  }
  connect() {
    this.connected = true;
    if (this.handlers.connected) this.handlers.connected();
  }
  disconnect() {
    this.connected = false;
  }
  newSubscription(channel, opts) {
    const sub = {
      channel,
      opts,
      handlers: {},
      on(event, fn) {
        this.handlers[event] = fn;
      },
      subscribe() {},
      unsubscribe() {},
      removeAllListeners() {
        this.handlers = {};
      },
      emitPublication(data) {
        if (this.handlers.publication) this.handlers.publication({ data });
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
    this.seen = new Set();
    this.maxSeen = 500;
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
      getToken: async () => {
        const res = await this.fetchImpl('/api/flatrate-live-chat/realtime/connect-token', {
          method: 'POST',
          body: '{}',
        });
        const json = await res.json();
        return json.token;
      },
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
      getToken: async () => {
        const res = await this.fetchImpl('/api/flatrate-live-chat/realtime/subscription-token', {
          method: 'POST',
          body: JSON.stringify({ roomKey }),
        });
        const json = await res.json();
        return json.token;
      },
    });
    sub.on('publication', (ctx) => this.handleEnvelope(ctx.data));
    sub.subscribe();
    this.subscriptions.set(roomKey, sub);
    return sub;
  }
  unsubscribe(roomKey) {
    if (!this.subscriptions.has(roomKey)) return;
    this.subscriptions.get(roomKey).unsubscribe();
    this.subscriptions.delete(roomKey);
  }
  handleEnvelope(envelope) {
    const key = eventDedupeKey(envelope);
    if (key) {
      if (this.seen.has(key)) return;
      this.seen.add(key);
    }
    this.onEvent(envelope);
  }
  disconnect() {
    for (const k of [...this.subscriptions.keys()]) this.unsubscribe(k);
    if (this.client) {
      this.client.disconnect();
      this.client = null;
    }
  }
}

async function main() {
  // --- dedupe ---
  const a = eventDedupeKey({ type: 'message.created', roomKey: 'community-general-live', order: 5 });
  const b = eventDedupeKey({ type: 'message.created', roomKey: 'community-general-live', order: 5 });
  const c = eventDedupeKey({ type: 'message.created', roomKey: 'community-general-live', order: 6 });
  assert.strictEqual(a, b);
  assert.notStrictEqual(a, c);
  assert.ok(a.startsWith('message.created|community-general-live|'));

  // --- channel helpers ---
  assert.strictEqual(channelForRoomKey('community-general-live'), '$flatrate-live-community-general-live');
  assert.strictEqual(roomKeyFromChannel('$flatrate-live-toyota-live'), 'toyota-live');
  assert.strictEqual(channelForRoomKey('Bad Key'), null);
  assert.strictEqual(roomKeyFromChannel('private-flatrate-live-x'), null);

  // --- not configured ---
  {
    const attrs = {
      'flatrate-live-chat.realtime.connect': false,
      'flatrate-live-chat.realtime.transport': 'CENTRIFUGO',
    };
    const client = new FlatRateRealtimeClient({
      Centrifuge: MockCentrifugeClient,
      app: { forum: { attribute: (k) => attrs[k] } },
    });
    assert.strictEqual(client.isConfigured(), false);
    assert.strictEqual(await client.connect(), false);
  }

  // --- configured connect + subscribe + dedupe ---
  {
    let events = 0;
    const attrs = {
      'flatrate-live-chat.realtime.connect': true,
      'flatrate-live-chat.realtime.configured': true,
      'flatrate-live-chat.realtime.transport': 'CENTRIFUGO',
      'flatrate-live-chat.realtime.websocketUrl': 'wss://example.test/connection/websocket',
    };
    const fetchImpl = async (url, opts) => {
      assert.ok(url.includes('connect-token') || url.includes('subscription-token'));
      if (url.includes('subscription-token')) {
        const body = JSON.parse(opts.body || '{}');
        assert.strictEqual(body.roomKey, 'community-general-live');
        assert.ok(!('channel' in body));
      }
      return {
        ok: true,
        json: async () => ({ token: 't', expiresAt: Date.now() / 1000 + 300 }),
      };
    };
    const client = new FlatRateRealtimeClient({
      Centrifuge: MockCentrifugeClient,
      fetchImpl,
      app: { forum: { attribute: (k) => attrs[k] } },
      onEvent: () => {
        events += 1;
      },
    });
    assert.strictEqual(client.isConfigured(), true);
    assert.strictEqual(await client.connect(), true);
    const sub = await client.subscribe('community-general-live');
    assert.ok(sub);
    assert.strictEqual(sub.channel, '$flatrate-live-community-general-live');
    const envelope = { type: 'message.created', roomKey: 'community-general-live', order: 9 };
    sub.emitPublication(envelope);
    sub.emitPublication(envelope);
    assert.strictEqual(events, 1);
    client.disconnect();
    assert.strictEqual(client.subscriptions.size, 0);
  }

  console.log('js_realtime_centrifugo_ok');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
