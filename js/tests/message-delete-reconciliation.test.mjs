import assert from 'node:assert/strict';
import { reconcileDeletedChatMessage, isConfirmedDeletedChatMessage } from '../src/forum/utils/reconcileDeletedChatMessage.js';

const chat = { id: () => '14' }, otherChat = { id: () => '15' };
const row = (id, room = chat) => ({ id: () => String(id), chat: () => room });
const target = row(36), unrelated = row(37), otherRoom = row(36, otherChat);
const state = () => ({ chatmessages: [target, unrelated, otherRoom] });

{
    const s = state(); let calls = 0, redraws = 0;
    const removed = await reconcileDeletedChatMessage({ state: s, chat, messageId: 36,
        findMessages: async () => ++calls === 1 ? [target] : [], pause: async () => {}, redraw: () => redraws++ });
    assert.equal(removed, true);
    assert.equal(calls, 2, 'A notification preceding persistence must be rechecked');
    assert.equal(redraws, 1);
    assert.deepEqual(s.chatmessages, [unrelated, otherRoom], 'Only the confirmed row in its room is removed');
    assert.equal(isConfirmedDeletedChatMessage(s, row(36)), true, 'A late history response cannot resurrect it');
    assert.equal(isConfirmedDeletedChatMessage(s, otherRoom), false);
}
for (const findMessages of [async () => [target], async () => { throw Error('offline'); }, async () => null]) {
    const s = state();
    assert.equal(await reconcileDeletedChatMessage({ state: s, chat, messageId: 36, findMessages, pause: async () => {} }), false);
    assert.deepEqual(s.chatmessages, [target, unrelated, otherRoom], 'Present or unverified data must be retained');
    assert.equal(isConfirmedDeletedChatMessage(s, target), false);
}
{
    const s = state(); let calls = 0;
    assert.equal(await reconcileDeletedChatMessage({ state: s, chat, messageId: undefined, findMessages: async () => { calls++; return []; } }), false);
    assert.equal(calls, 0);
}
console.log('AUTHORITATIVE_DELETION_RECONCILIATION=PASS');
