export const PRIMARY_ROOM_KEY = 'community-general-live';

export function roomKeyOf(chat) {
    if (!chat) return '';
    if (typeof chat.room_key === 'function') return chat.room_key() || '';
    if (typeof chat.roomKey === 'function') return chat.roomKey() || '';
    return chat.room_key || chat.roomKey || '';
}

/**
 * Presentation-only title. Does not change room identity.
 */
export function displayRoomTitle(chat, translator) {
    const trans = translator || (typeof app !== 'undefined' ? app.translator : null);
    if (roomKeyOf(chat) === PRIMARY_ROOM_KEY && trans) {
        return trans.trans('flatrate-live-chat.forum.live_chats.general_label');
    }
    if (chat && typeof chat.title === 'function') return chat.title();
    return (chat && chat.title) || '';
}

export function orderLiveDirectoryRooms(rooms) {
    const list = Array.isArray(rooms) ? rooms.slice() : [];
    const general = [];
    const rest = [];

    list.forEach((chat) => {
        if (roomKeyOf(chat) === PRIMARY_ROOM_KEY) general.push(chat);
        else rest.push(chat);
    });

    return general.concat(rest);
}

/**
 * Compact directory timestamps. Browser locale/timezone remain authoritative
 * for older dates. No extra date library.
 */
export function formatDirectoryTime(date, now = new Date()) {
    if (!date) return '';
    const then = date instanceof Date ? date : new Date(date);
    if (Number.isNaN(then.getTime())) return '';

    const diffMs = now.getTime() - then.getTime();
    const diffMin = Math.max(0, Math.round(diffMs / 60000));

    if (diffMin < 60) {
        return diffMin < 1 ? '1m' : `${diffMin}m`;
    }

    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const startOfThen = new Date(then.getFullYear(), then.getMonth(), then.getDate());
    const dayDiff = Math.round((startOfToday - startOfThen) / 86400000);

    if (dayDiff === 0) {
        return `${Math.max(1, Math.round(diffMin / 60))}h`;
    }
    if (dayDiff === 1) {
        return 'Yesterday';
    }

    return then.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

export function canAdministerRoom(chat, user) {
    if (!chat || !user) return false;

    try {
        const pivot = typeof user.chat_pivot === 'function' ? user.chat_pivot(chat.id()) : null;
        if (pivot && typeof pivot.role === 'function' && pivot.role()) return true;
    } catch (e) {
        // Missing pivot is a member, not an administrator.
    }

    if (typeof chat.creator === 'function' && !chat.creator() && typeof user.groups === 'function') {
        const groups = user.groups() || [];
        return groups.some((g) => g && String(g.id()) === '1');
    }

    return false;
}
