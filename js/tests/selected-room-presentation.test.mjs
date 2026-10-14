import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { createLiveMessagingProvider } from '../src/forum/liveMessagingProvider.js';
import { resetSelectedRoomPresenceCache } from '../src/forum/utils/selectedRoomPresence.js';
import { readSelectedConversation } from '../src/forum/utils/selectedRoomPresentation.js';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const { selectRoutedLiveRoom, rememberHydratedRoom, isDirectoryListedChat } = await import(
    pathToFileURL(join(dirname(fileURLToPath(import.meta.url)), '../src/forum/utils/selectRoutedLiveRoom.js')).href
);

function read(rel) {
    return readFileSync(join(root, rel), 'utf8');
}

function room(roomKey, id, title, extra = {}) {
    return {
        id: () => id,
        room_key: () => roomKey,
        title: () => title,
        visibility: () => extra.visibility || 'visible',
        audience: () => extra.audience || 'members',
        ...extra,
    };
}

function installApp(chats, userId) {
    const state = {
        chats,
        addChat(model) {
            this.chats.push(model);
        },
        ensureViewportState() {},
        setCurrentChat(model) {
            this.current = model;
        },
        getCurrentChat() {
            return this.current || null;
        },
        apiFetchChatByRoomKey(roomKey) {
            state.fetchCount = (state.fetchCount || 0) + 1;
            state.fetchedKey = roomKey;
            return state.fetchImpl(roomKey);
        },
        fetchImpl: async () => null,
        fetchCount: 0,
    };
    globalThis.app = {
        translator: {
            trans(key) {
                if (key === 'flatrate-live-chat.forum.live_chats.general_label') return 'FlatRate.wiki Live';
                return key;
            },
        },
        session: { user: userId == null ? null : { id: () => userId } },
        chat: state,
        request() {
            state.requestCount = (state.requestCount || 0) + 1;
            return Promise.resolve({});
        },
        forum: { attribute: () => false },
    };
    globalThis.m = { redraw() {} };
    return state;
}

function view() {
    return {
        selectionGeneration: 0,
        lastSelectedKey: null,
        unavailable: false,
        selecting: false,
        ensureChat() {},
        releaseRoomView() {},
        holdRoomView() {},
    };
}

test('exact authorized Audi exposure preserves the canonical title', async () => {
    resetSelectedRoomPresenceCache();
    const state = installApp([], '7');
    const audi = room('audi-live', 2, 'Audi Live', { visibility: 'hidden', audience: 'staff-preview' });
    state.fetchImpl = async () => rememberHydratedRoom(state, audi);
    selectRoutedLiveRoom(view(), 'audi-live');
    await Promise.resolve();
    await Promise.resolve();

    const snapshot = readSelectedConversation(globalThis.app, 'audi-live');
    assert.equal(snapshot.title, 'Audi Live');
    assert.equal(snapshot.privacy, 'public');
    assert.equal(snapshot.presentationTrusted, true);
    assert.equal(snapshot.sessionUserId, '7');
    assert.equal(snapshot.liveUserCount, null);
    assert.equal(isDirectoryListedChat(audi), false);
    assert.equal(state.fetchCount, 1);
});

test('the provider method is read-only and does not fetch or list hidden rooms', async () => {
    resetSelectedRoomPresenceCache();
    const state = installApp([], '7');
    const audi = room('audi-live', 2, 'Audi Live', { visibility: 'hidden', audience: 'staff-preview' });
    state.fetchImpl = async () => rememberHydratedRoom(state, audi);
    selectRoutedLiveRoom(view(), 'audi-live');
    await Promise.resolve();
    await Promise.resolve();
    const fetches = state.fetchCount;

    const provider = createLiveMessagingProvider({ app: globalThis.app });
    const before = state.chats.length;
    const snapshot = provider.getSelectedConversation({ key: 'audi-live' });
    assert.equal(snapshot.title, 'Audi Live');
    assert.equal(state.fetchCount, fetches);
    assert.equal(state.requestCount || 0, 0);
    assert.equal(state.chats.length, before);
    assert.equal(state.chats.filter(isDirectoryListedChat).length, 0);
});

test('a different room key and a stale session do not receive the Audi snapshot', async () => {
    resetSelectedRoomPresenceCache();
    const state = installApp([], '7');
    state.fetchImpl = async () => rememberHydratedRoom(state, room('audi-live', 2, 'Audi Live', { visibility: 'hidden', audience: 'staff-preview' }));
    selectRoutedLiveRoom(view(), 'audi-live');
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(readSelectedConversation(globalThis.app, 'ford-live'), null);
    globalThis.app.session.user = { id: () => '8' };
    assert.equal(readSelectedConversation(globalThis.app, 'audi-live'), null);
});

test('authorization failure is unavailable and does not keep another actor title', async () => {
    resetSelectedRoomPresenceCache();
    const state = installApp([], '7');
    state.fetchImpl = async () => {
        throw Object.assign(new Error('nope'), { status: 404 });
    };
    const fordView = view();
    selectRoutedLiveRoom(fordView, 'audi-live');
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(fordView.unavailable, true);
    const denied = readSelectedConversation(globalThis.app, 'audi-live');
    assert.equal(denied.unavailable, true);
    assert.equal(denied.title, undefined);

    globalThis.app.session.user = { id: () => '8' };
    assert.equal(readSelectedConversation(globalThis.app, 'audi-live'), null);
});

test('room switching ignores an in-flight response for the previous room', async () => {
    resetSelectedRoomPresenceCache();
    const state = installApp([], '7');
    let finishAudi;
    state.fetchImpl = (roomKey) => {
        if (roomKey === 'audi-live') {
            return new Promise((resolve) => {
                finishAudi = () =>
                    resolve(rememberHydratedRoom(state, room('audi-live', 2, 'Audi Live', { visibility: 'hidden', audience: 'staff-preview' })));
            });
        }
        return Promise.resolve(rememberHydratedRoom(state, room('ford-live', 3, 'Ford Live', { visibility: 'hidden', audience: 'staff-preview' })));
    };
    const surface = view();
    selectRoutedLiveRoom(surface, 'audi-live');
    selectRoutedLiveRoom(surface, 'ford-live');
    await Promise.resolve();
    await Promise.resolve();
    finishAudi();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(readSelectedConversation(globalThis.app, 'ford-live').title, 'Ford Live');
    assert.equal(readSelectedConversation(globalThis.app, 'audi-live'), null);
    assert.equal(surface.lastSelectedKey, 'ford-live');
});

test('session change drops the previously authorized hidden room', async () => {
    resetSelectedRoomPresenceCache();
    const state = installApp([], '7');
    state.fetchImpl = async () => rememberHydratedRoom(state, room('audi-live', 2, 'Audi Live', { visibility: 'hidden', audience: 'staff-preview' }));
    selectRoutedLiveRoom(view(), 'audi-live');
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(readSelectedConversation(globalThis.app, 'audi-live').title, 'Audi Live');

    globalThis.app.session.user = { id: () => '9' };
    state.fetchImpl = async () => {
        throw Object.assign(new Error('denied'), { status: 403 });
    };
    selectRoutedLiveRoom(view(), 'audi-live');
    await Promise.resolve();
    await Promise.resolve();
    const snapshot = readSelectedConversation(globalThis.app, 'audi-live');
    assert.equal(snapshot.unavailable, true);
    assert.equal(snapshot.title, undefined);
    assert.equal(
        state.chats.some((chat) => chat.room_key() === 'audi-live' && chat.flatratePresentationSessionUserId === '7'),
        false
    );
});

test('an unstamped in-memory room is confirmed with one exact lookup before the header can trust it', async () => {
    resetSelectedRoomPresenceCache();
    const stale = room('audi-live', 2, 'Audi Live', { visibility: 'hidden', audience: 'staff-preview' });
    const state = installApp([stale], '7');
    state.fetchImpl = async () => rememberHydratedRoom(state, stale);
    selectRoutedLiveRoom(view(), 'audi-live');
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(state.fetchCount, 1);
    assert.equal(state.chats.length, 1);
    assert.equal(readSelectedConversation(globalThis.app, 'audi-live').title, 'Audi Live');
});

test('selected-room presentation does not add a fetch, subscription, or directory enumeration', () => {
    const provider = read('js/src/forum/liveMessagingProvider.js');
    const presentation = read('js/src/forum/utils/selectedRoomPresentation.js');
    const selector = read('js/src/forum/utils/selectRoutedLiveRoom.js');
    assert.match(provider, /getSelectedConversation/);
    assert.match(provider, /readSelectedConversation/);
    assert.doesNotMatch(presentation, /apiFetchChatByRoomKey/);
    assert.doesNotMatch(presentation, /listConversations/);
    assert.doesNotMatch(presentation, /\.subscribe\(/);
    assert.match(selector, /stampAuthorizedRoom/);
    assert.match(selector, /exactRoomResultCurrent/);
    assert.doesNotMatch(selector, /canonicalBrandRoomKeys/);
});
