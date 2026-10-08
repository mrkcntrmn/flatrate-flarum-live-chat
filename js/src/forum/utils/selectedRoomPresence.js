/**
 * Count-only presence for the one selected Live room.
 * Does not enumerate rooms, open subscriptions, or invent a count.
 */

let cache = null;

export function resetSelectedRoomPresenceCache() {
    cache = null;
}

export function normalizePresenceCount(payload) {
    if (!payload || payload.available === false || payload.liveUserCount == null) {
        return { status: 'unknown', count: null };
    }
    const count = Number(payload.liveUserCount);
    if (!Number.isFinite(count) || count < 0) {
        return { status: 'unknown', count: null };
    }
    if (count === 0) {
        return { status: 'zero', count: 0 };
    }
    return { status: 'positive', count: Math.floor(count) };
}

export function presenceFailureResult(error) {
    const status = error && (error.status || (error.response && error.response.status));
    if (status === 403 || status === 404) {
        return { status: 'unauthorized', count: null };
    }
    return { status: 'unknown', count: null };
}

export function storedLiveUserCount(result) {
    if (!result || result.status === 'unknown' || result.status === 'unauthorized') return null;
    if (result.status === 'zero') return 0;
    if (result.status === 'positive') return result.count;
    return null;
}

export function beginSelectedRoomPresence(view, roomKey) {
    const ticket = {
        generation: view ? view.selectionGeneration : 0,
        roomKey: roomKey ? String(roomKey) : '',
    };
    if (!cache || cache.roomKey !== ticket.roomKey) {
        cache = null;
    }
    return ticket;
}

export function presenceTicketCurrent(view, ticket) {
    if (!view || !ticket) return false;
    if (view.selectionGeneration !== ticket.generation) return false;
    return String(view.lastSelectedKey || '') === String(ticket.roomKey || '');
}

export function rememberSelectedRoomPresence(view, ticket, result) {
    if (!presenceTicketCurrent(view, ticket)) return false;
    cache = {
        roomKey: ticket.roomKey,
        result,
        generation: ticket.generation,
    };
    return true;
}

export function cachedSelectedRoomPresence(roomKey) {
    if (!cache || !roomKey || cache.roomKey !== String(roomKey)) return null;
    return cache.result;
}

/**
 * Copy a cached count onto the conversation being rendered.
 * Refuses to write room A's count onto room B.
 * Does not insert a directory row.
 */
export function applyCachedPresence(conversation, roomKey) {
    const result = cachedSelectedRoomPresence(roomKey);
    if (!result || !conversation) return false;
    const id = conversation.sourceId || conversation.roomKey || conversation.key;
    if (id == null || String(id) !== String(roomKey)) return false;
    conversation.liveUserCount = storedLiveUserCount(result);
    return true;
}

export function scheduleSelectedRoomPresence(view, roomKey, deps = {}) {
    const ticket = beginSelectedRoomPresence(view, roomKey);
    const getApp = deps.getApp || (() => (typeof app !== 'undefined' ? app : null));
    const a = getApp();
    if (!ticket.roomKey || !a || typeof a.request !== 'function') {
        return Promise.resolve({ applied: false, stale: false, result: null });
    }
    if (!a.forum || typeof a.forum.attribute !== 'function' || !a.forum.attribute('flatrate-live-chat.realtime.connect')) {
        rememberSelectedRoomPresence(view, ticket, { status: 'unknown', count: null });
        return Promise.resolve({ applied: true, stale: false, result: cachedSelectedRoomPresence(ticket.roomKey) });
    }

    const request = deps.request || ((options) => a.request(options));
    const redraw =
        deps.redraw ||
        (() => {
            if (typeof m !== 'undefined' && m.redraw) m.redraw();
        });

    return Promise.resolve()
        .then(() =>
            request({
                method: 'POST',
                url: `${a.forum.attribute('apiUrl')}/flatrate-live-chat/realtime/presence-stats`,
                body: { roomKey: ticket.roomKey },
            })
        )
        .then((payload) => {
            if (!presenceTicketCurrent(view, ticket)) {
                return { applied: false, stale: true, result: null };
            }
            const result = normalizePresenceCount(payload);
            rememberSelectedRoomPresence(view, ticket, result);
            redraw();
            return { applied: true, stale: false, result };
        })
        .catch((error) => {
            if (!presenceTicketCurrent(view, ticket)) {
                return { applied: false, stale: true, result: null };
            }
            const result = presenceFailureResult(error);
            rememberSelectedRoomPresence(view, ticket, result);
            redraw();
            return { applied: true, stale: false, result };
        });
}
