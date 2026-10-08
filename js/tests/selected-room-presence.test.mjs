import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    applyCachedPresence,
    beginSelectedRoomPresence,
    cachedSelectedRoomPresence,
    normalizePresenceCount,
    presenceFailureResult,
    rememberSelectedRoomPresence,
    resetSelectedRoomPresenceCache,
    scheduleSelectedRoomPresence,
    storedLiveUserCount,
} from '../src/forum/utils/selectedRoomPresence.js';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const providerSrc = readFileSync(join(root, 'js/src/forum/liveMessagingProvider.js'), 'utf8');

function viewFor(roomKey, generation = 1) {
    return {
        selectionGeneration: generation,
        lastSelectedKey: roomKey,
    };
}

test('general positive count normalization is unchanged', () => {
    assert.deepEqual(normalizePresenceCount({ available: true, liveUserCount: 12 }), {
        status: 'positive',
        count: 12,
    });
    assert.equal(storedLiveUserCount(normalizePresenceCount({ available: true, liveUserCount: 12 })), 12);
});

test('brand positive count is stored only for the selected room', async () => {
    resetSelectedRoomPresenceCache();
    const view = viewFor('audi-live', 1);
    const calls = [];
    const result = await scheduleSelectedRoomPresence(view, 'audi-live', {
        getApp: () => ({
            request: (options) => {
                calls.push(options);
                return Promise.resolve({ available: true, liveUserCount: 4, roomKey: options.body.roomKey });
            },
            forum: {
                attribute(name) {
                    if (name === 'flatrate-live-chat.realtime.connect') return true;
                    if (name === 'apiUrl') return 'https://forum.example/api';
                    return null;
                },
            },
        }),
        redraw() {},
    });
    assert.equal(calls.length, 1);
    assert.equal(calls[0].body.roomKey, 'audi-live');
    assert.equal(calls[0].url, 'https://forum.example/api/flatrate-live-chat/realtime/presence-stats');
    assert.equal(result.applied, true);
    assert.equal(result.result.count, 4);
    const audi = { sourceId: 'audi-live', liveUserCount: null };
    const bmw = { sourceId: 'bmw-live', liveUserCount: null };
    assert.equal(applyCachedPresence(audi, 'audi-live'), true);
    assert.equal(audi.liveUserCount, 4);
    assert.equal(applyCachedPresence(bmw, 'bmw-live'), false);
    assert.equal(bmw.liveUserCount, null);
});

test('brand unknown count stays omitted', () => {
    resetSelectedRoomPresenceCache();
    const view = viewFor('audi-live');
    const ticket = beginSelectedRoomPresence(view, 'audi-live');
    rememberSelectedRoomPresence(view, ticket, normalizePresenceCount({ available: false, liveUserCount: null }));
    const conversation = { sourceId: 'audi-live', liveUserCount: 9 };
    applyCachedPresence(conversation, 'audi-live');
    assert.equal(conversation.liveUserCount, null);
    assert.equal(cachedSelectedRoomPresence('audi-live').status, 'unknown');
});

test('brand zero count is preserved as zero and not replaced with a placeholder', () => {
    resetSelectedRoomPresenceCache();
    const view = viewFor('audi-live');
    const ticket = beginSelectedRoomPresence(view, 'audi-live');
    rememberSelectedRoomPresence(view, ticket, normalizePresenceCount({ available: true, liveUserCount: 0 }));
    const conversation = { sourceId: 'audi-live' };
    applyCachedPresence(conversation, 'audi-live');
    assert.equal(conversation.liveUserCount, 0);
    assert.notEqual(conversation.liveUserCount, 1);
});

test('unauthorized brand access does not produce a count', () => {
    const result = presenceFailureResult({ status: 403 });
    assert.equal(result.status, 'unauthorized');
    assert.equal(storedLiveUserCount(result), null);
    assert.equal(storedLiveUserCount(presenceFailureResult({ status: 404 })), null);
});

test('room switching drops the previous room cache before the next response', () => {
    resetSelectedRoomPresenceCache();
    const view = viewFor('audi-live', 1);
    const audiTicket = beginSelectedRoomPresence(view, 'audi-live');
    rememberSelectedRoomPresence(view, audiTicket, { status: 'positive', count: 4 });
    view.selectionGeneration = 2;
    view.lastSelectedKey = 'bmw-live';
    beginSelectedRoomPresence(view, 'bmw-live');
    assert.equal(cachedSelectedRoomPresence('audi-live'), null);
    const bmw = { sourceId: 'bmw-live', liveUserCount: null };
    assert.equal(applyCachedPresence(bmw, 'bmw-live'), false);
    assert.equal(bmw.liveUserCount, null);
});

test('stale result suppression ignores a response after the room changes', async () => {
    resetSelectedRoomPresenceCache();
    const view = viewFor('audi-live', 1);
    let release;
    const pending = new Promise((resolve) => {
        release = resolve;
    });
    const scheduled = scheduleSelectedRoomPresence(view, 'audi-live', {
        getApp: () => ({
            request: () => pending,
            forum: {
                attribute(name) {
                    if (name === 'flatrate-live-chat.realtime.connect') return true;
                    if (name === 'apiUrl') return 'https://forum.example/api';
                    return null;
                },
            },
        }),
        redraw() {},
    });
    view.selectionGeneration = 2;
    view.lastSelectedKey = 'bmw-live';
    beginSelectedRoomPresence(view, 'bmw-live');
    release({ available: true, liveUserCount: 8 });
    const settled = await scheduled;
    assert.equal(settled.stale, true);
    assert.equal(settled.applied, false);
    const bmw = { sourceId: 'bmw-live' };
    assert.equal(applyCachedPresence(bmw, 'bmw-live'), false);
    assert.equal(bmw.liveUserCount, undefined);
});

test('failed presence endpoint falls back without a fabricated count', async () => {
    resetSelectedRoomPresenceCache();
    const view = viewFor('audi-live', 3);
    const settled = await scheduleSelectedRoomPresence(view, 'audi-live', {
        getApp: () => ({
            request: () => Promise.reject(new Error('offline')),
            forum: {
                attribute(name) {
                    if (name === 'flatrate-live-chat.realtime.connect') return true;
                    if (name === 'apiUrl') return 'https://forum.example/api';
                    return null;
                },
            },
        }),
        redraw() {},
    });
    assert.equal(settled.result.status, 'unknown');
    assert.equal(settled.result.count, null);
});

test('rapid navigation keeps only the latest selected room', async () => {
    resetSelectedRoomPresenceCache();
    const view = viewFor('audi-live', 1);
    const rooms = [];
    const first = scheduleSelectedRoomPresence(view, 'audi-live', {
        getApp: () => ({
            request: (options) => {
                rooms.push(options.body.roomKey);
                return Promise.resolve({ available: true, liveUserCount: 2 });
            },
            forum: {
                attribute(name) {
                    if (name === 'flatrate-live-chat.realtime.connect') return true;
                    if (name === 'apiUrl') return 'https://forum.example/api';
                    return null;
                },
            },
        }),
        redraw() {},
    });
    view.selectionGeneration = 2;
    view.lastSelectedKey = 'bmw-live';
    const second = scheduleSelectedRoomPresence(view, 'bmw-live', {
        getApp: () => ({
            request: (options) => {
                rooms.push(options.body.roomKey);
                return Promise.resolve({ available: true, liveUserCount: 6 });
            },
            forum: {
                attribute(name) {
                    if (name === 'flatrate-live-chat.realtime.connect') return true;
                    if (name === 'apiUrl') return 'https://forum.example/api';
                    return null;
                },
            },
        }),
        redraw() {},
    });
    const [a, b] = await Promise.all([first, second]);
    assert.equal(a.stale, true);
    assert.equal(b.applied, true);
    assert.equal(b.result.count, 6);
    assert.deepEqual(rooms, ['audi-live', 'bmw-live']);
    const bmw = { key: 'bmw-live' };
    applyCachedPresence(bmw, 'bmw-live');
    assert.equal(bmw.liveUserCount, 6);
});

test('unmount invalidates an in-flight selected-room response', async () => {
    resetSelectedRoomPresenceCache();
    const view = viewFor('audi-live', 1);
    let release;
    const pending = new Promise((resolve) => {
        release = resolve;
    });
    const scheduled = scheduleSelectedRoomPresence(view, 'audi-live', {
        getApp: () => ({
            request: () => pending,
            forum: {
                attribute(name) {
                    if (name === 'flatrate-live-chat.realtime.connect') return true;
                    if (name === 'apiUrl') return 'https://forum.example/api';
                    return null;
                },
            },
        }),
        redraw() {},
    });
    view.selectionGeneration += 1;
    release({ available: true, liveUserCount: 3 });
    const settled = await scheduled;
    assert.equal(settled.stale, true);
    assert.equal(cachedSelectedRoomPresence('audi-live'), null);
});

test('general directory count path still updates only the primary room', () => {
    assert.match(providerSrc, /fetchPrimaryPresenceCount/);
    assert.match(providerSrc, /roomKey !== PRIMARY_ROOM_KEY/);
    assert.match(providerSrc, /rows\[primaryIndex\] = \{ \.\.\.rows\[primaryIndex\], liveUserCount: count \};/);
    assert.match(providerSrc, /applyCachedPresence\(conversation, key\)/);
    assert.doesNotMatch(providerSrc, /for\s*\(const row of rows\)/);
    assert.doesNotMatch(providerSrc, /room-catalog\.json/);
});
