import { displayRoomTitle, roomKeyOf } from './liveChatPresentation.js';

export function activityAtFromChat(chat) {
    if (!chat) return null;
    const last = typeof chat.last_message === 'function' ? chat.last_message() : chat.last_message;
    if (!last) return null;
    const created = typeof last.created_at === 'function' ? last.created_at() : last.created_at;
    if (!created) return null;
    if (created instanceof Date) {
        return Number.isNaN(created.getTime()) ? null : created.toISOString();
    }
    if (typeof created === 'string') {
        const parsed = new Date(created);
        return Number.isNaN(parsed.getTime()) ? created : parsed.toISOString();
    }
    if (typeof created.toISOString === 'function') {
        return created.toISOString();
    }
    return null;
}

export function unreadCountOf(chat) {
    if (!chat) return 0;
    if (typeof chat.unreaded === 'function') return Number(chat.unreaded()) || 0;
    return Number(chat.unreaded) || 0;
}

/**
 * Normalize a live-chats directory model into a messaging-shell row.
 * Only the supplied chat is used — callers must not inject catalog keys.
 */
export function normalizeLiveConversation(chat) {
    const roomKey = roomKeyOf(chat);
    if (!roomKey) return null;

    return {
        id: 'live:' + roomKey,
        kind: 'live',
        key: roomKey,
        title: displayRoomTitle(chat),
        activityAt: activityAtFromChat(chat),
        unreadCount: unreadCountOf(chat),
        isPublic: true,
        userId: null,
        roomKey,
    };
}

export function normalizeLiveDirectory(models) {
    const list = Array.isArray(models) ? models : models ? [models] : [];
    return list.map(normalizeLiveConversation).filter(Boolean);
}
