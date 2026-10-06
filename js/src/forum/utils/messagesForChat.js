/**
 * Render scope for a single chat. Shared ChatState storage stays intact.
 * Identity is the server chat id. Missing chat or relationship fails closed.
 */

export function messageBelongsToChat(message, chat) {
    if (!chat || typeof chat.id !== 'function' || chat.id() == null) return false;
    if (!message || typeof message.chat !== 'function') return false;
    const related = message.chat();
    if (!related || typeof related.id !== 'function' || related.id() == null) return false;
    return String(related.id()) === String(chat.id());
}

export function messagesForChat(messages, chat) {
    if (!Array.isArray(messages)) return [];
    return messages.filter((message) => messageBelongsToChat(message, chat));
}
