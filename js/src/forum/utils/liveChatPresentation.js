export const PRIMARY_ROOM_KEY = 'community-general-live';

export function roomKeyOf(chat) {
    if (!chat) return '';
    // F2 live-rooms payloads use camelCase `roomKey`. The historical model also
    // exposes snake_case `room_key` as an attribute function that returns empty
    // when only camelCase is present — do not return early on that empty value.
    const fromFn = (name) => {
        if (typeof chat[name] !== 'function') return '';
        const value = chat[name]();
        return value == null ? '' : String(value);
    };
    return (
        fromFn('roomKey') ||
        fromFn('room_key') ||
        (chat.roomKey != null && chat.roomKey !== '' ? String(chat.roomKey) : '') ||
        (chat.room_key != null && chat.room_key !== '' ? String(chat.room_key) : '') ||
        ''
    );
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

function modelType(model) {
    if (!model) return 0;
    return typeof model.type === 'function' ? model.type() : model.type;
}

/**
 * Read-only Room info. Canonical Live rooms are type=1 channels and do not
 * list participant usernames. Type 0 Direct/group chats still do.
 * A null chip vnode means no UsersTags container is rendered.
 */
export function readOnlyRoomInfoPresentation(model, renderParticipantChips) {
    const title = model && typeof model.title === 'function' ? model.title() : model && model.title;
    const showParticipantChips = !modelType(model);
    return {
        title: title || '',
        participantChips: showParticipantChips && typeof renderParticipantChips === 'function' ? renderParticipantChips() : null,
    };
}

/**
 * Which Room info surface the modal should open.
 * Direct conversations stay read-only even for a participant who is also a moderator.
 * Channel and group management stays behind the existing moderator flag.
 */
export function roomInfoSurface(model, { isDirectPm = false, isModerator = false } = {}) {
    if (modelType(model)) {
        return isModerator ? 'channel-moderator' : 'channel-readonly';
    }
    if (isDirectPm) return 'direct-readonly';
    return isModerator ? 'group-moderator' : 'group-readonly';
}

export function roomInfoShowsParticipantManagement(surface) {
    return surface === 'channel-moderator' || surface === 'group-moderator';
}
