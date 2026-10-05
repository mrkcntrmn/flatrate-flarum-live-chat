/**
 * active_conversation follows the General Live room view.
 * Remembered Messages selection must not acquire or keep the reason.
 */
export const ACTIVE_CONVERSATION_REASON = 'active_conversation';

export function createActiveConversationLifecycle({ acquire, release, hasReason } = {}) {
    let heldRoomKey = null;

    return {
        heldRoomKey() {
            return heldRoomKey;
        },

        async enter(roomKey) {
            if (!roomKey || typeof acquire !== 'function') return null;
            if (heldRoomKey && heldRoomKey !== roomKey && typeof release === 'function') {
                release(heldRoomKey);
            }
            heldRoomKey = roomKey;
            if (typeof hasReason === 'function' && hasReason(roomKey)) {
                return null;
            }
            const sub = await acquire(roomKey);
            if (heldRoomKey !== roomKey) {
                if (typeof release === 'function') release(roomKey);
                return null;
            }
            return sub;
        },

        leave(roomKey) {
            if (!heldRoomKey) return;
            if (roomKey && heldRoomKey !== roomKey) return;
            const key = heldRoomKey;
            heldRoomKey = null;
            if (typeof release === 'function') release(key);
        },
    };
}
