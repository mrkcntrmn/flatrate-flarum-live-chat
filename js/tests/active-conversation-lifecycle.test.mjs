/**
 * active_conversation follows the General Live room view, not remembered selection.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { createActiveConversationLifecycle } from '../src/forum/activeConversationLifecycle.js';
import {
    ACTIVE_CONVERSATION_REASON,
    GENERAL_LIVE_PRESENCE_STORAGE_KEY,
    PERSISTENT_LIVE_REASON,
    createLiveMainProvider,
} from '../src/forum/liveMainProvider.js';
import FlatRateRealtimeClient from '../src/forum/realtime/FlatRateRealtimeClient.js';

const ROOM = 'community-general-live';
const OTHER = 'community-brand-sample';
const root = dirname(fileURLToPath(import.meta.url));

class MockCentrifugeClient {
    constructor() {
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
    newSubscription(channel) {
        const sub = {
            channel,
            subscribeCalls: 0,
            unsubscribeCalls: 0,
            on() {},
            subscribe() {
                this.subscribeCalls += 1;
            },
            unsubscribe() {
                this.unsubscribeCalls += 1;
            },
            removeAllListeners() {},
        };
        this.subs.set(channel, sub);
        return sub;
    }
}

function harness() {
    const forumAttrs = {
        'flatrate-live-chat.main_live_available': true,
        'flatrate-live-chat.general_live_enabled': true,
        'flatrate-live-chat.realtime.connect': true,
        'flatrate-live-chat.realtime.transport': 'CENTRIFUGO',
        'flatrate-live-chat.realtime.websocketUrl': 'wss://example.test/connection/websocket',
        apiUrl: '/api',
    };
    const app = {
        session: { user: { id: () => 1 } },
        forum: { attribute: (key) => forumAttrs[key] },
        request: async () => ({ liveUserCount: 2, available: true }),
    };
    const client = new FlatRateRealtimeClient({
        app,
        Centrifuge: MockCentrifugeClient,
        fetchImpl: async () => ({ ok: true, json: async () => ({ token: 't' }) }),
    });
    const storage = {
        data: {},
        getItem(key) {
            return Object.prototype.hasOwnProperty.call(this.data, key) ? this.data[key] : null;
        },
        setItem(key, value) {
            this.data[key] = String(value);
        },
    };
    const provider = createLiveMainProvider({
        app,
        storage,
        realtime: client,
        pollMs: 0,
        redraw() {},
    });
    const lifecycle = createActiveConversationLifecycle({
        acquire: (roomKey) => client.acquire(roomKey, ACTIVE_CONVERSATION_REASON),
        release: (roomKey) => client.release(roomKey, ACTIVE_CONVERSATION_REASON),
        hasReason: (roomKey) => client.hasReason(roomKey, ACTIVE_CONVERSATION_REASON),
    });
    return { client, provider, storage, lifecycle };
}

function physical(client, roomKey = ROOM) {
    return client.subscriptions.has(roomKey) ? 1 : 0;
}

test('entering the General Live view acquires active_conversation', async () => {
    const { client, lifecycle } = harness();
    await lifecycle.enter(ROOM);
    assert.equal(client.hasReason(ROOM, ACTIVE_CONVERSATION_REASON), true);
    assert.equal(physical(client), 1);
    assert.equal(client.client.subs.get('$flatrate-live-community-general-live').subscribeCalls, 1);
});

test('leaving the room view releases active_conversation while selected chat remains', async () => {
    const { client, lifecycle } = harness();
    let selectedChat = null;
    await lifecycle.enter(ROOM);
    selectedChat = ROOM;
    lifecycle.leave(ROOM);
    assert.equal(selectedChat, ROOM);
    assert.equal(lifecycle.heldRoomKey(), null);
    assert.equal(client.hasReason(ROOM, ACTIVE_CONVERSATION_REASON), false);
    assert.equal(client.hasReason(ROOM, PERSISTENT_LIVE_REASON), false);
    assert.equal(physical(client), 0);
});

test('double enter and double leave keep one subscription and no negative ownership', async () => {
    const { client, lifecycle } = harness();
    await lifecycle.enter(ROOM);
    await lifecycle.enter(ROOM);
    assert.equal(client.reasonCount(ROOM), 1);
    assert.equal(client.client.subs.get('$flatrate-live-community-general-live').subscribeCalls, 1);
    lifecycle.leave(ROOM);
    lifecycle.leave(ROOM);
    lifecycle.leave();
    assert.equal(client.reasonCount(ROOM), 0);
    assert.equal(physical(client), 0);
});

test('switching rooms releases the previous active conversation', async () => {
    const { client, lifecycle } = harness();
    await lifecycle.enter(ROOM);
    await lifecycle.enter(OTHER);
    assert.equal(client.hasReason(ROOM, ACTIVE_CONVERSATION_REASON), false);
    assert.equal(client.hasReason(OTHER, ACTIVE_CONVERSATION_REASON), true);
    assert.equal(physical(client, ROOM), 0);
    assert.equal(physical(client, OTHER), 1);
    lifecycle.leave(OTHER);
    assert.equal(client.hasReason(OTHER, ACTIVE_CONVERSATION_REASON), false);
    assert.equal(physical(client, OTHER), 0);
});

test('personal Live keeps the physical subscription after the room view exits', async () => {
    const { client, provider, lifecycle, storage } = harness();
    assert.equal(await provider.setUserLive(true), true);
    assert.equal(storage.getItem(GENERAL_LIVE_PRESENCE_STORAGE_KEY), '1');
    assert.equal(client.hasReason(ROOM, PERSISTENT_LIVE_REASON), true);
    assert.equal(client.hasReason(ROOM, ACTIVE_CONVERSATION_REASON), false);
    assert.equal(physical(client), 1);

    await lifecycle.enter(ROOM);
    assert.equal(client.reasonCount(ROOM), 2);
    assert.equal(physical(client), 1);
    assert.equal(client.client.subs.get('$flatrate-live-community-general-live').subscribeCalls, 1);

    lifecycle.leave(ROOM);
    assert.equal(client.hasReason(ROOM, ACTIVE_CONVERSATION_REASON), false);
    assert.equal(client.hasReason(ROOM, PERSISTENT_LIVE_REASON), true);
    assert.equal(physical(client), 1);

    assert.equal(await provider.setUserLive(false), false);
    assert.equal(client.hasReason(ROOM, PERSISTENT_LIVE_REASON), false);
    assert.equal(physical(client), 0);
});

test('selected chat assignment does not own active_conversation', () => {
    const chatState = readFileSync(join(root, '../src/forum/states/ChatState.js'), 'utf8');
    const body = chatState.slice(chatState.indexOf('setCurrentChat(model)'), chatState.indexOf('getCurrentChat()'));
    assert.equal(body.includes('subscribeRoomChannel'), false);
    assert.equal(body.includes('unsubscribeRoomChannel'), false);
    assert.match(chatState, /holdActiveConversation\(roomKey, owner\)/);
    assert.match(chatState, /releaseActiveConversation\(roomKey, owner\)/);

    for (const file of ['components/MessagesLiveConversationView.js', 'components/LiveConversationView.js']) {
        const view = readFileSync(join(root, '../src/forum', file), 'utf8');
        assert.match(view, /onremove\(vnode\)/);
        assert.match(view, /holdActiveConversation\(roomKey, this\.conversationOwner\)/);
        assert.match(view, /releaseActiveConversation\(/);
    }
});
