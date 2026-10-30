function key(chatId, messageId) {
    return `${chatId}:${messageId}`;
}

export function isConfirmedDeletedChatMessage(state, message) {
    return state.confirmedDeletedChatMessages?.has(key(message.chat?.()?.id?.(), message.id?.())) || false;
}

/** Confirm deletion through authorized HTTP history before removing a cached row.
 * The deletion event can precede the database delete, so retry a bounded number
 * of times. A failed request or a still-visible row must preserve the message.
 */
export async function reconcileDeletedChatMessage({ state, chat, messageId, findMessages, redraw = () => {}, pause = ms => new Promise(resolve => setTimeout(resolve, ms)) }) {
    if (!chat?.id?.() || !/^[1-9]\d*$/.test(String(messageId))) return false;
    for (const delay of [0, 100, 300]) {
        if (delay) await pause(delay);
        let messages;
        try {
            messages = await findMessages();
        } catch {
            return false;
        }
        if (!Array.isArray(messages)) return false;
        if (messages.some(message => String(message.id()) === String(messageId))) continue;

        state.confirmedDeletedChatMessages ??= new Set();
        state.confirmedDeletedChatMessages.add(key(chat.id(), messageId));
        state.chatmessages = state.chatmessages.filter(message => !isConfirmedDeletedChatMessage(state, message));
        redraw();
        return true;
    }
    return false;
}
