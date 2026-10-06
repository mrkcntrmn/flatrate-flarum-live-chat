import assert from 'assert';
import fs from 'fs';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function makeStore() {
    const data = { chatmessages: {} };
    return {
        data,
        getById(type, id) {
            const bucket = data[type];
            if (!bucket) return undefined;
            return bucket[String(id)];
        },
        index(model) {
            data.chatmessages[String(model.id())] = model;
        },
    };
}

function makeChat(roomKey) {
    let last = null;
    return {
        roomKey,
        id: () => roomKey,
        pushData(payload) {
            if (payload && payload.relationships && Object.prototype.hasOwnProperty.call(payload.relationships, 'last_message')) {
                last = payload.relationships.last_message;
            }
        },
        last_message() {
            return last;
        },
    };
}

function makeModel({ id, message, userId, chat, store, createdAt = '2026-10-06T12:00:00.000Z' }) {
    const model = {
        data: {
            type: 'chatmessages',
            id,
            attributes: { message, created_at: createdAt },
        },
        store,
        exists: !(id === 0 || id === '0'),
        isNeedToFlash: false,
        isTimedOut: false,
        isEditing: false,
        content: message,
        id() {
            return this.data.id;
        },
        message() {
            return this.data.attributes.message;
        },
        created_at() {
            return new Date(this.data.attributes.created_at);
        },
        user() {
            return { id: () => userId };
        },
        chat() {
            return chat;
        },
        pushData(payload) {
            if (payload && Object.prototype.hasOwnProperty.call(payload, 'id')) this.data.id = payload.id;
            if (payload && payload.attributes) Object.assign(this.data.attributes, payload.attributes);
            return this;
        },
    };
    return model;
}

function insertChatMessage(state, model) {
    if (state.chatmessages.find((entry) => entry.id() == model.id())) return null;
    state.chatmessages.push(model);
    return model;
}

function countId(state, id) {
    return state.chatmessages.filter((entry) => entry.id() == id).length;
}

async function main() {
    const { applyPostedChatMessage, reconcilePostedChatMessage } = await import(
        pathToFileURL(path.join(__dirname, '../src/forum/utils/reconcilePostedChatMessage.js')).href
    );
    const { groupChatMessages } = await import(pathToFileURL(path.join(__dirname, '../src/forum/utils/groupChatMessages.js')).href);

    const chatStateSrc = fs.readFileSync(path.join(__dirname, '../src/forum/states/ChatState.js'), 'utf8');
    const viewportSrc = fs.readFileSync(path.join(__dirname, '../src/forum/states/ViewportState.js'), 'utf8');
    const reconcileSrc = fs.readFileSync(path.join(__dirname, '../src/forum/utils/reconcilePostedChatMessage.js'), 'utf8');

    assert.ok(chatStateSrc.includes('applyPostedChatMessage(this, model, r.data)'));
    assert.ok(chatStateSrc.includes("case 'message.post'"));
    assert.ok(chatStateSrc.includes('message.user() != app.session.user'));
    assert.ok(!reconcileSrc.includes('.message()'));
    assert.ok(!reconcileSrc.includes('unreaded'));

    const sendStart = viewportSrc.indexOf('messageSend() {');
    const sendBody = viewportSrc.slice(sendStart, viewportSrc.indexOf('messageEdit(model)', sendStart));
    assert.ok(sendBody.includes('!this.loadingSend'));
    const postStart = viewportSrc.indexOf('messagePost(model) {');
    const postBody = viewportSrc.slice(postStart, viewportSrc.indexOf('inputClear()', postStart));
    assert.ok(postBody.indexOf('this.loadingSend = true') < postBody.indexOf('postChatMessage'));
    console.log('SINGLE_SEND_GUARD=PASS');

    // Ordering 1: authoritative refetch lands before POST resolves.
    {
        const store = makeStore();
        const chat = makeChat('community-general-live');
        const state = { chatmessages: [] };
        const optimistic = makeModel({ id: 0, message: 'Whatsup draft', userId: 6, chat, store });
        optimistic.isEditing = true;
        store.data.chatmessages['0'] = optimistic;
        state.chatmessages.push(optimistic);
        chat.pushData({ relationships: { last_message: optimistic } });

        const authoritative = makeModel({
            id: 123,
            message: 'Whatsup @tech_20031',
            userId: 6,
            chat,
            store,
            createdAt: '2026-10-06T12:00:01.000Z',
        });
        authoritative.exists = true;
        store.index(authoritative);
        assert.ok(insertChatMessage(state, authoritative));
        assert.strictEqual(countId(state, 123), 1);
        assert.strictEqual(countId(state, 0), 1);

        const survivor = applyPostedChatMessage(state, optimistic, {
            id: 123,
            attributes: { message: 'Whatsup @tech_20031' },
        });

        assert.strictEqual(survivor, authoritative);
        assert.strictEqual(survivor.id(), 123);
        assert.strictEqual(survivor.message(), 'Whatsup @tech_20031');
        assert.strictEqual(survivor.isNeedToFlash, true);
        assert.strictEqual(survivor.isEditing, false);
        assert.strictEqual(survivor.isTimedOut, false);
        assert.strictEqual(countId(state, 123), 1);
        assert.strictEqual(state.chatmessages.length, 1);
        assert.strictEqual(chat.last_message(), survivor);
        assert.strictEqual(store.getById('chatmessages', '123'), authoritative);
        assert.strictEqual(store.data.chatmessages['0'], undefined);

        const groups = groupChatMessages(state.chatmessages, { sessionUser: { id: () => 6 } });
        assert.strictEqual(groups.length, 1);
        assert.strictEqual(groups[0].messages.length, 1);
        console.log('REALTIME_BEFORE_POST=PASS');
    }

    // Ordering 2: POST resolves, then the refetch tries to insert the same id.
    {
        const store = makeStore();
        const chat = makeChat('community-general-live');
        const state = { chatmessages: [] };
        const optimistic = makeModel({ id: 0, message: 'Testing', userId: 6, chat, store });
        state.chatmessages.push(optimistic);

        const stored = makeModel({ id: 123, message: 'Testing', userId: 6, chat, store });
        stored.exists = true;
        store.index(stored);

        const survivor = applyPostedChatMessage(state, optimistic, {
            id: 123,
            attributes: { message: 'Testing' },
        });
        assert.strictEqual(survivor, stored);
        assert.strictEqual(countId(state, 123), 1);

        const refetched = stored;
        assert.strictEqual(insertChatMessage(state, refetched), null);
        const otherObjectSameId = makeModel({ id: 123, message: 'Testing', userId: 6, chat, store });
        assert.strictEqual(insertChatMessage(state, otherObjectSameId), null);
        assert.strictEqual(countId(state, 123), 1);
        assert.strictEqual(state.chatmessages.length, 1);
        assert.strictEqual(chat.last_message(), survivor);
        assert.strictEqual(store.getById('chatmessages', '123'), stored);
        console.log('POST_BEFORE_REALTIME=PASS');
    }

    // Identical text with distinct persisted ids stays two messages.
    {
        const store = makeStore();
        const chat = makeChat('community-general-live');
        const state = { chatmessages: [] };
        const first = makeModel({ id: 123, message: 'Testing', userId: 6, chat, store });
        const second = makeModel({ id: 124, message: 'Testing', userId: 6, chat, store });
        store.index(first);
        store.index(second);
        state.chatmessages.push(first, second);

        const survivor = reconcilePostedChatMessage(state, first);
        assert.strictEqual(survivor, first);
        assert.strictEqual(state.chatmessages.length, 2);
        assert.deepStrictEqual(
            state.chatmessages.map((entry) => entry.id()),
            [123, 124]
        );
        console.log('IDENTICAL_TEXT_DISTINCT_IDS=PASS');
    }

    // Same text, different authors.
    {
        const store = makeStore();
        const chat = makeChat('community-general-live');
        const state = { chatmessages: [] };
        const first = makeModel({ id: 123, message: 'Hello', userId: 6, chat, store });
        const second = makeModel({ id: 124, message: 'Hello', userId: 322, chat, store });
        state.chatmessages.push(first, second);
        reconcilePostedChatMessage(state, first);
        assert.strictEqual(state.chatmessages.length, 2);
        assert.strictEqual(state.chatmessages[1].user().id(), 322);
        console.log('SAME_TEXT_DIFFERENT_USERS=PASS');
    }

    // Same text, different rooms.
    {
        const store = makeStore();
        const general = makeChat('community-general-live');
        const ford = makeChat('ford-live');
        const state = { chatmessages: [] };
        const generalMessage = makeModel({ id: 123, message: 'Testing', userId: 6, chat: general, store });
        const fordMessage = makeModel({ id: 124, message: 'Testing', userId: 6, chat: ford, store });
        state.chatmessages.push(generalMessage, fordMessage);
        reconcilePostedChatMessage(state, generalMessage);
        assert.strictEqual(state.chatmessages.length, 2);
        assert.strictEqual(state.chatmessages[1].chat().roomKey, 'ford-live');
        console.log('SAME_TEXT_DIFFERENT_ROOMS=PASS');
    }

    // Failed POST does not reconcile. The timed-out optimistic row stays recoverable.
    {
        const store = makeStore();
        const chat = makeChat('community-general-live');
        const state = { chatmessages: [] };
        const optimistic = makeModel({ id: 0, message: 'Testing', userId: 6, chat, store });
        state.chatmessages.push(optimistic);
        optimistic.isTimedOut = true;
        assert.strictEqual(reconcilePostedChatMessage(state, optimistic), optimistic);
        assert.strictEqual(state.chatmessages.length, 1);
        assert.strictEqual(state.chatmessages[0].id(), 0);
        assert.strictEqual(optimistic.isTimedOut, true);
        const postAt = chatStateSrc.indexOf('postChatMessage(model)');
        const postFn = chatStateSrc.slice(postAt, chatStateSrc.indexOf('editChatMessage(model, sync', postAt));
        assert.ok(postFn.includes('model.isTimedOut = true'));
        const timeoutAt = postFn.lastIndexOf('model.isTimedOut = true');
        assert.ok(!postFn.slice(timeoutAt).includes('applyPostedChatMessage'));
        console.log('FAILED_POST_PRESERVES_OPTIMISTIC=PASS');
    }

    console.log('MESSAGE_SEND_RECONCILIATION=PASS');
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
