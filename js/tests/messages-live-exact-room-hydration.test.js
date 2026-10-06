import assert from 'assert';
import fs from 'fs';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '../..');

function read(rel) {
    return fs.readFileSync(path.join(root, rel), 'utf8');
}

const { selectRoutedLiveRoom, rememberHydratedRoom, isDirectoryListedChat } = await import(
    pathToFileURL(path.join(__dirname, '../src/forum/utils/selectRoutedLiveRoom.js')).href
);

function room(roomKey, id, extra = {}) {
    return {
        id: () => id,
        room_key: () => roomKey,
        visibility: () => extra.visibility || 'visible',
        audience: () => extra.audience || 'members',
        ...extra,
    };
}

function harness(chats = []) {
    const calls = { fetch: 0, current: [], held: [], released: [] };
    let fetchImpl = async () => null;
    const state = {
        chats,
        viewportStates: {},
        addChat(model) {
            this.chats.push(model);
            this.viewportStates[model.id()] = { model };
        },
        ensureViewportState(model) {
            if (!this.viewportStates[model.id()]) this.viewportStates[model.id()] = { model };
        },
        setCurrentChat(model) {
            calls.current.push(model);
            this.current = model;
        },
        getCurrentChat() {
            return this.current || null;
        },
        apiFetchChatByRoomKey(roomKey) {
            calls.fetch += 1;
            calls.fetchedKey = roomKey;
            return fetchImpl(roomKey);
        },
    };
    globalThis.app = { chat: state };
    globalThis.m = { redraw() {} };
    const view = {
        selectionGeneration: 0,
        lastSelectedKey: null,
        unavailable: false,
        selecting: false,
        ensureChat() {},
        releaseRoomView(roomKey) {
            calls.released.push(roomKey);
        },
        holdRoomView(roomKey) {
            calls.held.push(roomKey);
        },
    };
    return { state, view, calls, setFetch(fn) { fetchImpl = fn; } };
}

const viewSrc = read('js/src/forum/components/MessagesLiveConversationView.js');
const legacySrc = read('js/src/forum/components/LiveConversationView.js');
const stateSrc = read('js/src/forum/states/ChatState.js');
assert.ok(viewSrc.includes('selectRoutedLiveRoom'));
assert.ok(!viewSrc.includes('apiFetchChats'));
assert.ok(legacySrc.includes('selectRoutedLiveRoom'));
assert.ok(!legacySrc.includes('apiFetchChats'));
assert.ok(stateSrc.includes('/flatrate-live-chat/rooms/'));
assert.ok(!stateSrc.includes('member_beta'));
assert.ok(!viewSrc.includes('room-catalog.json'));
assert.ok(!read('js/src/forum/utils/selectRoutedLiveRoom.js').includes('canonicalBrandRoomKeys'));

// A. Absent from the directory collection; exact hydration selects Ford.
{
    const h = harness([]);
    const ford = room('ford-live', 27, { visibility: 'hidden', audience: 'staff-preview' });
    h.setFetch(async () => rememberHydratedRoom(h.state, ford));
    selectRoutedLiveRoom(h.view, 'ford-live');
    await Promise.resolve();
    await Promise.resolve();
    assert.strictEqual(h.calls.fetch, 1);
    assert.strictEqual(h.calls.fetchedKey, 'ford-live');
    assert.strictEqual(h.view.unavailable, false);
    assert.strictEqual(h.calls.current.length, 1);
    assert.strictEqual(h.calls.current[0].room_key(), 'ford-live');
    assert.strictEqual(h.calls.held.at(-1), 'ford-live');
    assert.ok(h.state.viewportStates[27]);
    assert.strictEqual(h.state.chats.filter((chat) => chat.room_key() === 'ford-live').length, 1);
    assert.strictEqual(isDirectoryListedChat(ford), false);
}

// B. Exact hydration rejects.
{
    const h = harness([]);
    h.setFetch(async () => {
        throw new Error('404');
    });
    selectRoutedLiveRoom(h.view, 'ford-live');
    await Promise.resolve();
    await Promise.resolve();
    assert.strictEqual(h.view.unavailable, true);
    assert.ok(h.calls.current.every((chat) => chat == null));
    assert.deepStrictEqual(h.calls.held, []);
}

// C. Ford response arrives after Toyota was selected.
{
    const h = harness([]);
    let finishFord;
    h.setFetch((roomKey) => {
        if (roomKey === 'ford-live') {
            return new Promise((resolve) => {
                finishFord = () => resolve(rememberHydratedRoom(h.state, room('ford-live', 27, { visibility: 'hidden', audience: 'staff-preview' })));
            });
        }
        return Promise.resolve(rememberHydratedRoom(h.state, room('toyota-live', 28, { visibility: 'hidden', audience: 'staff-preview' })));
    });
    selectRoutedLiveRoom(h.view, 'ford-live');
    selectRoutedLiveRoom(h.view, 'toyota-live');
    await Promise.resolve();
    await Promise.resolve();
    finishFord();
    await Promise.resolve();
    await Promise.resolve();
    assert.strictEqual(h.view.lastSelectedKey, 'toyota-live');
    assert.strictEqual(h.calls.current.at(-1).room_key(), 'toyota-live');
    assert.ok(!h.calls.current.some((chat) => chat.room_key() === 'ford-live'));
    assert.ok(!h.calls.held.includes('ford-live') || h.calls.held.at(-1) === 'toyota-live');
    assert.strictEqual(h.calls.held.at(-1), 'toyota-live');
}

// D. Already hydrated: no second fetch and no second model.
{
    const ford = room('ford-live', 27, { visibility: 'hidden', audience: 'staff-preview' });
    const h = harness([ford]);
    h.state.viewportStates[27] = { model: ford };
    h.setFetch(async () => {
        throw new Error('should not fetch');
    });
    selectRoutedLiveRoom(h.view, 'ford-live');
    assert.strictEqual(h.calls.fetch, 0);
    assert.strictEqual(h.calls.current[0], ford);
    assert.strictEqual(h.state.chats.length, 1);
    const again = rememberHydratedRoom(h.state, room('ford-live', 27, { visibility: 'hidden', audience: 'staff-preview' }));
    assert.strictEqual(again, ford);
    assert.strictEqual(h.state.chats.length, 1);
}

// E. General Live already in the client is selected without an exact fetch.
{
    const general = room('community-general-live', 43);
    const h = harness([general]);
    h.setFetch(async () => {
        throw new Error('should not fetch');
    });
    selectRoutedLiveRoom(h.view, 'community-general-live');
    assert.strictEqual(h.calls.fetch, 0);
    assert.strictEqual(h.view.unavailable, false);
    assert.strictEqual(h.calls.current[0].room_key(), 'community-general-live');
    assert.strictEqual(isDirectoryListedChat(general), true);
}

// Hidden exact room stays out of the directory filter. General stays in it.
{
    const h = harness([room('community-general-live', 43)]);
    const ford = room('ford-live', 27, { visibility: 'hidden', audience: 'staff-preview' });
    rememberHydratedRoom(h.state, ford);
    const listed = h.state.chats.filter(isDirectoryListedChat).map((chat) => chat.room_key());
    assert.deepStrictEqual(listed, ['community-general-live']);
    assert.strictEqual(h.state.chats.length, 2);
}

console.log('messages-live-exact-room-hydration: ok');
