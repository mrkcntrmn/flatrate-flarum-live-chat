/**
 * Browser presentation contract for Navigation MAIN Live row.
 * Navigation must not import ChatState or Centrifugo internals.
 */

import { PRIMARY_ROOM_KEY } from './utils/liveChatPresentation.js';

export const GENERAL_LIVE_PRESENCE_STORAGE_KEY = 'flatrate:general-live-presence:v1';
export const PERSISTENT_LIVE_REASON = 'persistent_user_live';
export const ACTIVE_CONVERSATION_REASON = 'active_conversation';
export const CANONICAL_GENERAL_LIVE_HREF = '/messages/live/community-general-live';

function readPreferredLive(storage) {
    try {
        const raw = storage.getItem(GENERAL_LIVE_PRESENCE_STORAGE_KEY);
        if (raw === null || raw === undefined || raw === '') return false;
        return raw === '1' || raw === 'true' || raw === true;
    } catch (e) {
        return false;
    }
}

function writePreferredLive(storage, value) {
    try {
        storage.setItem(GENERAL_LIVE_PRESENCE_STORAGE_KEY, value ? '1' : '0');
    } catch (e) {
        // Private mode / quota — preference is best-effort.
    }
}

export function createLiveMainProvider(options = {}) {
    const getApp = () => options.app || (typeof app !== 'undefined' ? app : null);
    const storage = options.storage || (typeof localStorage !== 'undefined' ? localStorage : null);
    const pollMs = typeof options.pollMs === 'number' ? options.pollMs : 30000;

    let preferredLive = storage ? readPreferredLive(storage) : false;
    let liveCount = null; // number | null (unknown)
    let started = false;
    let pollTimer = null;
    let redraw =
        options.redraw ||
        (() => {
            if (typeof m !== 'undefined' && m.redraw) m.redraw();
        });

    function realtime() {
        const a = getApp();
        return options.realtime || a?.flatrateLiveRealtime || null;
    }

    function available() {
        const a = getApp();
        if (!a?.session?.user) return false;
        if (!a.forum?.attribute?.('flatrate-live-chat.permissions.enabled')) return false;
        // Explicit forum attribute from server (migration-safe: absent serialized as true).
        if (a.forum.attribute('flatrate-live-chat.general_live_enabled') === false) return false;
        if (a.forum.attribute('flatrate-live-chat.general_live_enabled') !== true) {
            // Fail closed if attribute missing from an unexpected payload.
            return false;
        }
        return true;
    }

    function userLive() {
        // Effective background Live: preference AND admin gate.
        return preferredLive && available();
    }

    function href() {
        return CANONICAL_GENERAL_LIVE_HREF;
    }

    async function syncPersistentSubscription() {
        const rt = realtime();
        if (!rt || typeof rt.acquire !== 'function' || typeof rt.release !== 'function') {
            return;
        }
        if (userLive()) {
            try {
                await rt.acquire(PRIMARY_ROOM_KEY, PERSISTENT_LIVE_REASON);
            } catch (e) {
                // Token denial / admin off mid-flight — fail closed.
                releasePersistentLocally(rt);
            }
        } else {
            rt.release(PRIMARY_ROOM_KEY, PERSISTENT_LIVE_REASON);
        }
    }

    function releasePersistentLocally(rt) {
        const client = rt || realtime();
        if (client && typeof client.release === 'function') {
            client.release(PRIMARY_ROOM_KEY, PERSISTENT_LIVE_REASON);
        }
    }

    async function setUserLive(value) {
        preferredLive = !!value;
        if (storage) writePreferredLive(storage, preferredLive);
        await syncPersistentSubscription();
        redraw();
        return userLive();
    }

    async function refreshLiveCount() {
        const a = getApp();
        if (!available()) {
            liveCount = null;
            return null;
        }
        if (!a.forum.attribute('flatrate-live-chat.realtime.connect')) {
            liveCount = null;
            return null;
        }
        try {
            const payload = await a.request({
                method: 'POST',
                url: a.forum.attribute('apiUrl') + '/flatrate-live-chat/realtime/presence-stats',
                body: { roomKey: PRIMARY_ROOM_KEY },
            });
            if (!payload || payload.available === false) {
                liveCount = null;
                return null;
            }
            const value = payload.liveUserCount;
            if (value === null || value === undefined) {
                liveCount = null;
                return null;
            }
            const count = Number(value);
            liveCount = Number.isFinite(count) && count >= 0 ? Math.floor(count) : null;
            return liveCount;
        } catch (e) {
            // Admin OFF / auth failure — treat as unavailable for MAIN, release presence.
            liveCount = null;
            if (e && (e.status === 403 || e.status === 404)) {
                releasePersistentLocally();
                redraw();
            }
            return null;
        }
    }

    /**
     * Called when the browser discovers General Live became disabled.
     * Stored preference remains; effective LIVE becomes false.
     */
    function handleAdminDisabled() {
        releasePersistentLocally();
        liveCount = null;
        redraw();
    }

    async function start() {
        if (started) return;
        started = true;
        await syncPersistentSubscription();
        await refreshLiveCount();
        if (pollMs > 0 && typeof setInterval !== 'undefined') {
            pollTimer = setInterval(() => {
                refreshLiveCount().then(() => redraw());
            }, pollMs);
        }
        redraw();
    }

    function stop() {
        started = false;
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        releasePersistentLocally();
    }

    return {
        available,
        liveCount: () => liveCount,
        userLive,
        setUserLive,
        href,
        refreshLiveCount,
        start,
        stop,
        handleAdminDisabled,
        // Test/introspection helpers (stable keys)
        storageKey: GENERAL_LIVE_PRESENCE_STORAGE_KEY,
        preferredLive: () => preferredLive,
        syncPersistentSubscription,
    };
}
