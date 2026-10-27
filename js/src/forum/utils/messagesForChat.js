/**
 * Render scope for a single chat. Shared ChatState storage stays intact.
 * Identity is the server chat id. Missing chat or relationship fails closed.
 */

export function messageBelongsToChat(message, chat) {
    if (!chat || typeof chat.id !== 'function' || chat.id() == null) return false;
    if (!message) return false;

    let relatedId = null;
    if (typeof message.chat === 'function') {
        try {
            const related = message.chat();
            if (related && typeof related.id === 'function' && related.id() != null) {
                relatedId = related.id();
            }
        } catch (e) {
            relatedId = null;
        }
    }
    if (relatedId == null && typeof message.chatId === 'function') {
        relatedId = message.chatId();
    }
    if (relatedId == null && typeof message.chat_id === 'function') {
        relatedId = message.chat_id();
    }
    if (relatedId == null && message.data && message.data.attributes) {
        relatedId = message.data.attributes.chatId ?? message.data.attributes.chat_id ?? null;
    }
    if (relatedId == null) return false;
    return String(relatedId) === String(chat.id());
}

export function messagesForChat(messages, chat) {
    if (!Array.isArray(messages)) return [];
    return messages.filter((message) => messageBelongsToChat(message, chat));
}
