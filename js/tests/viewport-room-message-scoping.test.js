import assert from 'assert';
import fs from 'fs';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function chat(id) {
    return { id: () => id };
}

function message({ id, chatModel, text = 'm' }) {
    return {
        id: () => id,
        chat: () => chatModel,
        message: () => text,
        type: () => null,
        user: () => ({ id: () => 6 }),
        created_at: () => new Date('2026-10-06T12:00:00.000Z'),
    };
}

function ids(list) {
    return list.map((entry) => entry.id());
}

async function main() {
    const { messagesForChat } = await import(pathToFileURL(path.join(__dirname, '../src/forum/utils/messagesForChat.js')).href);
    const { groupChatMessages } = await import(pathToFileURL(path.join(__dirname, '../src/forum/utils/groupChatMessages.js')).href);
    const { applyPostedChatMessage } = await import(pathToFileURL(path.join(__dirname, '../src/forum/utils/reconcilePostedChatMessage.js')).href);

    const viewport = fs.readFileSync(path.join(__dirname, '../src/forum/components/ChatViewport.js'), 'utf8');
    const renderStart = viewport.indexOf('componentsChatMessages(chat)');
    const renderBody = viewport.slice(renderStart, viewport.indexOf('componentsChatMessageGroups(messages)', renderStart));
    assert.ok(renderBody.includes('messagesForChat'));
    assert.ok(renderBody.indexOf('messagesForChat') < renderBody.indexOf('groupChatMessages') || !renderBody.includes('groupChatMessages'));
    assert.ok(!renderBody.includes('getChatMessages()'));

    const ford = chat(10);
    const general = chat(43);
    const toyota = chat(40);
    const fordMessage = message({ id: 101, chatModel: ford, text: 'BETA-FORD' });
    const generalMessage = message({ id: 201, chatModel: general, text: 'BETA-GENERAL' });
    const shared = [fordMessage, generalMessage];

    assert.deepStrictEqual(ids(messagesForChat(shared, general)), [201]);
    assert.deepStrictEqual(ids(messagesForChat(shared, ford)), [101]);
    console.log('TEST_BRAND_TO_GENERAL=PASS');
    console.log('TEST_GENERAL_TO_BRAND=PASS');

    let current = ford;
    assert.deepStrictEqual(ids(messagesForChat(shared, current)), [101]);
    current = general;
    assert.deepStrictEqual(ids(messagesForChat(shared, current)), [201]);
    current = ford;
    assert.deepStrictEqual(ids(messagesForChat(shared, current)), [101]);
    assert.strictEqual(shared.length, 2);
    console.log('TEST_CACHED_SWITCH=PASS');

    const fordRealtime = message({ id: 102, chatModel: ford, text: 'BETA-FORD-RT' });
    shared.push(fordRealtime);
    assert.deepStrictEqual(ids(messagesForChat(shared, general)), [201]);
    assert.deepStrictEqual(ids(messagesForChat(shared, ford)), [101, 102]);
    console.log('TEST_INACTIVE_ROOM_REALTIME=PASS');

    const generalRealtime = message({ id: 202, chatModel: general, text: 'BETA-GENERAL-RT' });
    shared.push(generalRealtime);
    assert.deepStrictEqual(ids(messagesForChat(shared, general)), [201, 202]);
    console.log('TEST_ACTIVE_ROOM_REALTIME=PASS');

    const orphan = { id: () => 999, chat: () => null };
    shared.push(orphan);
    assert.ok(!ids(messagesForChat(shared, general)).includes(999));
    assert.ok(!ids(messagesForChat(shared, ford)).includes(999));
    console.log('TEST_MISSING_RELATIONSHIP=PASS');

    assert.deepStrictEqual(messagesForChat(shared, null), []);
    assert.deepStrictEqual(messagesForChat(shared, { id: () => null }), []);
    console.log('TEST_CURRENT_ROOM_UNLOADED=PASS');

    const hydratedFord = message({ id: 103, chatModel: ford, text: 'hydrated' });
    const hydrated = [hydratedFord, generalMessage];
    assert.deepStrictEqual(ids(messagesForChat(hydrated, ford)), [103]);
    assert.deepStrictEqual(ids(messagesForChat(hydrated, general)), [201]);
    console.log('TEST_BETA003_HYDRATION_REGRESSION=PASS');

    const store = {
        data: { chatmessages: {} },
        getById(type, id) {
            return this.data[type] && this.data[type][String(id)];
        },
    };
    const optimistic = {
        data: { type: 'chatmessages', id: 0, attributes: { message: 'smoke' } },
        exists: false,
        isNeedToFlash: false,
        isTimedOut: false,
        isEditing: true,
        id() {
            return this.data.id;
        },
        message() {
            return this.data.attributes.message;
        },
        chat() {
            return general;
        },
        pushData(payload) {
            if (payload && Object.prototype.hasOwnProperty.call(payload, 'id')) this.data.id = payload.id;
            if (payload && payload.attributes) Object.assign(this.data.attributes, payload.attributes);
            return this;
        },
    };
    store.data.chatmessages['0'] = optimistic;
    const persisted = {
        data: { id: 31, attributes: { message: 'smoke' } },
        exists: true,
        id() {
            return this.data.id;
        },
        message() {
            return this.data.attributes.message;
        },
        chat() {
            return general;
        },
        pushData() {
            return this;
        },
    };
    store.data.chatmessages['31'] = persisted;
    const state = { chatmessages: [optimistic, persisted, fordMessage] };
    const survivor = applyPostedChatMessage(state, optimistic, { id: 31, attributes: { message: 'smoke' } });
    assert.strictEqual(survivor.id(), 31);
    assert.deepStrictEqual(ids(messagesForChat(state.chatmessages, general)), [31]);
    assert.deepStrictEqual(ids(messagesForChat(state.chatmessages, ford)), [101]);
    assert.strictEqual(state.chatmessages.filter((entry) => entry.id() == 31).length, 1);
    console.log('TEST_BETA002_RECONCILIATION_REGRESSION=PASS');

    const early = message({ id: 301, chatModel: general });
    early.created_at = () => new Date('2026-10-06T12:00:00.000Z');
    const late = message({ id: 302, chatModel: general });
    late.created_at = () => new Date('2026-10-06T12:00:30.000Z');
    const between = message({ id: 303, chatModel: toyota });
    between.created_at = () => new Date('2026-10-06T12:00:10.000Z');
    const scoped = messagesForChat([early, between, late], general);
    const groups = groupChatMessages(scoped, { sessionUser: { id: () => 6 } });
    assert.strictEqual(groups.length, 1);
    assert.deepStrictEqual(
        groups[0].messages.map((entry) => entry.id()),
        [301, 302]
    );
    console.log('TEST_GROUPING_AFTER_FILTER=PASS');
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
