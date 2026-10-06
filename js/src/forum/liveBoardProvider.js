/**
 * Browser presentation contract for one Brand-board Live pin.
 * Count polling is limited to the active board. Preview does not acquire
 * realtime presence or persistent Live.
 */

import { brandRoomHref, isCanonicalBrandBoardKey } from './brandBoardCatalog.js';

export function createLiveBoardProvider(options = {}) {
    const getApp = () => options.app || (typeof app !== 'undefined' ? app : null);
    const pollMs = typeof options.pollMs === 'number' ? options.pollMs : 30000;
    const setIntervalFn = options.setInterval || (typeof setInterval !== 'undefined' ? setInterval : null);
    const clearIntervalFn = options.clearInterval || (typeof clearInterval !== 'undefined' ? clearInterval : null);
    const redraw =
        options.redraw ||
        (() => {
            if (typeof m !== 'undefined' && m.redraw) m.redraw();
        });

    let activeBoardKey = null;
    let liveCount = null;
    let pollTimer = null;
    let requestGeneration = 0;

    function actorAvailable() {
        const a = getApp();
        if (!a?.session?.user) return false;
        // Fail closed when the actor-effective field is absent.
        // Do not fall back to the admin-preview attribute.
        return a.forum?.attribute?.('flatrate-live-chat.brand_live_available') === true;
    }

    function available(boardKey) {
        if (!isCanonicalBrandBoardKey(boardKey)) return false;
        return actorAvailable();
    }

    function href(boardKey) {
        if (!available(boardKey)) return null;
        return brandRoomHref(boardKey);
    }

    function stopPoll() {
        if (pollTimer && clearIntervalFn) {
            clearIntervalFn(pollTimer);
        }
        pollTimer = null;
    }

    async function refreshLiveCount() {
        const boardKey = activeBoardKey;
        const generation = requestGeneration;
        const a = getApp();
        if (!boardKey || !available(boardKey) || !a?.request) {
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
                url: `${a.forum.attribute('apiUrl')}/flatrate-live-chat/realtime/presence-stats`,
                body: { roomKey: `${boardKey}-live` },
            });
            if (generation !== requestGeneration || activeBoardKey !== boardKey) {
                return liveCount;
            }
            if (!payload || payload.available === false || payload.liveUserCount == null) {
                liveCount = null;
                return null;
            }
            const count = Number(payload.liveUserCount);
            liveCount = Number.isFinite(count) && count >= 0 ? Math.floor(count) : null;
            return liveCount;
        } catch (e) {
            if (generation === requestGeneration && activeBoardKey === boardKey) {
                liveCount = null;
            }
            return null;
        }
    }

    function startPoll() {
        stopPoll();
        if (!setIntervalFn || pollMs <= 0 || !activeBoardKey) return;
        pollTimer = setIntervalFn(() => {
            refreshLiveCount().then(() => redraw());
        }, pollMs);
    }

    async function activate(boardKey) {
        if (!available(boardKey)) {
            deactivate();
            return false;
        }
        if (activeBoardKey !== boardKey) {
            requestGeneration += 1;
            activeBoardKey = boardKey;
            liveCount = null;
        }
        await refreshLiveCount();
        startPoll();
        redraw();
        return true;
    }

    function deactivate(boardKey) {
        if (boardKey && activeBoardKey !== boardKey) return;
        requestGeneration += 1;
        activeBoardKey = null;
        liveCount = null;
        stopPoll();
        redraw();
    }

    return {
        available,
        liveCount(boardKey) {
            if (!boardKey || boardKey !== activeBoardKey) return null;
            return liveCount;
        },
        href,
        activate,
        deactivate,
        activeBoardKey: () => activeBoardKey,
        activePollCount: () => (pollTimer ? 1 : 0),
    };
}
