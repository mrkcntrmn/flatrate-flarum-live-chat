/**
 * FORUM-LIVE-MAIN-001D — actor-effective MAIN gate and persistent-presence lifecycle.
 * Uses the real provider and realtime client. Navigation is optional via FORUM_NAV_ROOT.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import test from 'node:test';
import { pathToFileURL } from 'node:url';

import {
    ACTIVE_CONVERSATION_REASON,
    GENERAL_LIVE_PRESENCE_STORAGE_KEY,
    PERSISTENT_LIVE_REASON,
    createLiveMainProvider,
} from '../src/forum/liveMainProvider.js';
import FlatRateRealtimeClient from '../src/forum/realtime/FlatRateRealtimeClient.js';

const ROOM = 'community-general-live';

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

function memoryStorage(seed = {}) {
    const data = { ...seed };
    return {
        getItem(key) {
            return Object.prototype.hasOwnProperty.call(data, key) ? data[key] : null;
        },
        setItem(key, value) {
            data[key] = String(value);
        },
    };
}

function harness({ signedIn = true, mainLive = true, stored = null } = {}) {
    const forumAttrs = {
        'flatrate-live-chat.main_live_available': mainLive,
        'flatrate-live-chat.general_live_enabled': true,
        'flatrate-live-chat.realtime.connect': true,
        'flatrate-live-chat.realtime.transport': 'CENTRIFUGO',
        'flatrate-live-chat.realtime.websocketUrl': 'wss://example.test/connection/websocket',
        apiUrl: '/api',
    };
    const app = {
        session: { user: signedIn ? { id: () => 1 } : null },
        forum: { attribute: (key) => forumAttrs[key] },
        request: async () => ({ liveUserCount: 3, available: true }),
    };
    const client = new FlatRateRealtimeClient({
        app,
        Centrifuge: MockCentrifugeClient,
        fetchImpl: async () => ({ ok: true, json: async () => ({ token: 't' }) }),
    });
    const storage = memoryStorage(stored == null ? {} : { [GENERAL_LIVE_PRESENCE_STORAGE_KEY]: stored });
    const provider = createLiveMainProvider({
        app,
        storage,
        realtime: client,
        pollMs: 0,
        redraw() {},
    });
    return { app, forumAttrs, client, storage, provider };
}

function reasons(client) {
    return client.roomReasons.get(ROOM) || new Set();
}

test('provider.available follows the server effective gate only', () => {
    const on = harness({ mainLive: true });
    assert.equal(on.provider.available(), true);
    on.forumAttrs['flatrate-live-chat.main_live_available'] = false;
    assert.equal(on.provider.available(), false);

    const guest = harness({ signedIn: false, mainLive: true });
    assert.equal(guest.provider.available(), false);

    const missing = harness({ mainLive: true });
    delete missing.forumAttrs['flatrate-live-chat.main_live_available'];
    assert.equal(missing.provider.available(), false);
});

test('personal preference off does not open a persistent reason', async () => {
    const { provider, client } = harness({ mainLive: true });
    assert.equal(provider.userLive(), false);
    await provider.syncPersistentSubscription();
    assert.equal(client.subscriptions.size, 0);
    assert.equal(reasons(client).has(PERSISTENT_LIVE_REASON), false);
});

test('setUserLive(true) acquires one persistent reason', async () => {
    const { provider, client, storage } = harness({ mainLive: true });
    assert.equal(await provider.setUserLive(true), true);
    assert.equal(storage.getItem(GENERAL_LIVE_PRESENCE_STORAGE_KEY), '1');
    assert.equal(client.subscriptions.size, 1);
    assert.equal(client.reasonCount(ROOM), 1);
    assert.equal(reasons(client).has(PERSISTENT_LIVE_REASON), true);
});

test('active conversation plus personal Live is one physical subscription', async () => {
    const { provider, client } = harness({ mainLive: true });
    await provider.setUserLive(true);
    await client.acquire(ROOM, ACTIVE_CONVERSATION_REASON);
    assert.equal(client.subscriptions.size, 1);
    assert.equal(client.reasonCount(ROOM), 2);
    const channel = '$flatrate-live-community-general-live';
    assert.equal(client.client.subs.get(channel).subscribeCalls, 1);

    client.release(ROOM, ACTIVE_CONVERSATION_REASON);
    assert.equal(client.subscriptions.size, 1);
    assert.equal(reasons(client).has(PERSISTENT_LIVE_REASON), true);
    assert.equal(reasons(client).has(ACTIVE_CONVERSATION_REASON), false);
});

test('rollout true to false releases persistent Live and keeps an open conversation', async () => {
    const { provider, client, forumAttrs, storage } = harness({ mainLive: true });
    await provider.setUserLive(true);
    await client.acquire(ROOM, ACTIVE_CONVERSATION_REASON);
    forumAttrs['flatrate-live-chat.main_live_available'] = false;
    assert.equal(await provider.refreshLiveCount(), null);
    assert.equal(provider.available(), false);
    assert.equal(provider.userLive(), false);
    assert.equal(provider.preferredLive(), true);
    assert.equal(storage.getItem(GENERAL_LIVE_PRESENCE_STORAGE_KEY), '1');
    assert.equal(reasons(client).has(PERSISTENT_LIVE_REASON), false);
    assert.equal(reasons(client).has(ACTIVE_CONVERSATION_REASON), true);
    assert.equal(client.subscriptions.size, 1);
});

test('stored preference restores persistent Live when the gate returns', async () => {
    const { provider, client, forumAttrs } = harness({ mainLive: true, stored: '1' });
    assert.equal(provider.preferredLive(), true);
    await provider.refreshLiveCount();
    assert.equal(reasons(client).has(PERSISTENT_LIVE_REASON), true);

    forumAttrs['flatrate-live-chat.main_live_available'] = false;
    await provider.refreshLiveCount();
    assert.equal(reasons(client).has(PERSISTENT_LIVE_REASON), false);
    assert.equal(provider.preferredLive(), true);

    forumAttrs['flatrate-live-chat.main_live_available'] = true;
    await provider.refreshLiveCount();
    assert.equal(provider.userLive(), true);
    assert.equal(reasons(client).has(PERSISTENT_LIVE_REASON), true);
    assert.equal(provider.preferredLive(), true);
});

test('setUserLive(true) while the gate is off stores preference and does not subscribe', async () => {
    const { provider, client, storage } = harness({ mainLive: false });
    assert.equal(await provider.setUserLive(true), false);
    assert.equal(storage.getItem(GENERAL_LIVE_PRESENCE_STORAGE_KEY), '1');
    assert.equal(provider.preferredLive(), true);
    assert.equal(client.subscriptions.size, 0);
    assert.equal(reasons(client).has(PERSISTENT_LIVE_REASON), false);
});

test('master off makes the provider unavailable and releases persistent Live', async () => {
    const { provider, client, forumAttrs } = harness({ mainLive: true, stored: '1' });
    await provider.refreshLiveCount();
    assert.equal(reasons(client).has(PERSISTENT_LIVE_REASON), true);

    forumAttrs['flatrate-live-chat.general_live_enabled'] = false;
    forumAttrs['flatrate-live-chat.main_live_available'] = false;
    provider.handleAdminDisabled();
    assert.equal(provider.available(), false);
    assert.equal(provider.userLive(), false);
    assert.equal(provider.preferredLive(), true);
    assert.equal(client.subscriptions.size, 0);
    assert.equal(reasons(client).has(PERSISTENT_LIVE_REASON), false);
});

test('count refresh reads liveUserCount only', async () => {
    let body = null;
    const seen = harness({ mainLive: true });
    seen.app.request = async (req) => {
        body = req.body;
        return { liveUserCount: 4, available: true, users: ['should-not-be-used'] };
    };
    assert.equal(await seen.provider.refreshLiveCount(), 4);
    assert.deepEqual(body, { roomKey: ROOM });
    assert.equal(seen.provider.liveCount(), 4);
    const src = readFileSync(new URL('../src/forum/liveMainProvider.js', import.meta.url), 'utf8');
    assert.equal(src.includes('displayName'), false);
    assert.equal(src.includes('avatar'), false);
});

test('navigation source is not required to encode rollout settings', async (t) => {
    const navRoot = process.env.FORUM_NAV_ROOT;
    if (!navRoot) {
        t.skip('FORUM_NAV_ROOT is unset; cross-package matrix runs in local qualification');
        return;
    }

    const utilPath = join(navRoot, 'js/src/forum/utils/mainLiveChat.js');
    const pinPath = join(navRoot, 'js/src/forum/components/MainLiveChatPin.js');
    const utilSrc = readFileSync(utilPath, 'utf8');
    const pinSrc = readFileSync(pinPath, 'utf8');
    assert.equal(utilSrc.includes('general_live_admin_preview_enabled'), false);
    assert.equal(utilSrc.includes('general_live_user_enabled'), false);
    assert.equal(pinSrc.includes('general_live_admin_preview_enabled'), false);
    assert.equal(pinSrc.includes('OFFLINE'), false);
    assert.equal(pinSrc.includes('COMING SOON'), false);
    assert.equal(pinSrc.includes('PREVIEW'), false);
    assert.match(utilSrc, /provider\.available\(\) === true/);

    const nav = await import(pathToFileURL(utilPath).href);

    function rowFor(mainLive, signedIn) {
        const { provider } = harness({ mainLive, signedIn });
        return nav.shouldShowMainLiveChat({
            signedIn,
            pathname: '/',
            page: 1,
            provider,
        });
    }

    assert.equal(rowFor(false, true), false, 'case A/E rollout or master unavailable');
    assert.equal(rowFor(false, false), false, 'guest');
    assert.equal(rowFor(true, true), true, 'admin preview or user live');
    assert.equal(rowFor(true, false), false, 'guest even if attribute were true');

    const visible = harness({ mainLive: true });
    const base = { signedIn: true, pathname: '/', page: 1, provider: visible.provider };
    assert.equal(nav.shouldShowMainLiveChat({ ...base, searchParams: { q: 'brakes' } }), false);
    assert.equal(nav.shouldShowMainLiveChat({ ...base, routeName: 'following' }), false);
    assert.equal(nav.shouldShowMainLiveChat({ ...base, currentTag: { slug: () => 'toyota' } }), false);
    assert.equal(nav.shouldShowMainLiveChat({ ...base, page: 2 }), false);
    assert.equal(nav.shouldShowMainLiveChat({ ...base, searchParams: { sort: 'latest' } }), true);
    assert.equal(nav.isLiveMainProviderAvailable(visible.provider), true);
    assert.equal(typeof visible.provider.userLive, 'function');
    assert.equal(typeof visible.provider.setUserLive, 'function');
    assert.equal(visible.provider.href(), '/messages/live/community-general-live');
});

test('provider module does not duplicate admin rollout keys', () => {
    const src = readFileSync(join(dirname(new URL(import.meta.url).pathname), '../src/forum/liveMainProvider.js'), 'utf8');
    assert.match(src, /flatrate-live-chat\.main_live_available/);
    assert.equal(src.includes('general_live_admin_preview_enabled'), false);
    assert.equal(src.includes('general_live_user_enabled'), false);
});
