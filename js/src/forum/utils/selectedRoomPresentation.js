import { roomKeyOf } from './liveChatPresentation.js';
import { normalizeLiveConversation } from './normalizeLiveConversation.js';

/**
 * Read-only presentation snapshot for the one selected Live room.
 * Does not fetch, mutate, or enumerate the hidden catalog.
 * A room is trusted only after this session's authorized exact-room result
 * stamps the model. PUBLIC is the existing Live presentation semantic from
 * that model, not a guess from the route key.
 */
export function presentationSessionUserId(appInstance) {
    const user = appInstance && appInstance.session ? appInstance.session.user : null;
    if (!user) return null;
    const id = typeof user.id === 'function' ? user.id() : user.id;
    if (id == null || id === '') return null;
    return String(id);
}

export function invalidateSelectedRoomPresentation(state) {
    if (!state) return;
    state.flatrateExactRoomResult = null;
    if (Array.isArray(state.chats)) {
        state.chats.forEach((chat) => {
            if (chat) chat.flatratePresentationSessionUserId = null;
        });
        state.chats = state.chats.filter((chat) => !chat || !chat.flatrateRouteHydratedOnly);
    }
}

export function bindPresentationSession(state, sessionUserId) {
    if (!state) return sessionUserId;
    if (state.flatratePresentationSessionUserId !== undefined && state.flatratePresentationSessionUserId !== sessionUserId) {
        invalidateSelectedRoomPresentation(state);
    }
    state.flatratePresentationSessionUserId = sessionUserId;
    return sessionUserId;
}

export function noteExactRoomResult(state, result) {
    if (!state || !result || !result.sessionUserId || !result.roomKey) return;
    state.flatrateExactRoomResult = {
        status: result.status,
        roomKey: String(result.roomKey),
        sessionUserId: String(result.sessionUserId),
    };
}

export function stampAuthorizedRoom(model, sessionUserId) {
    if (!model || !sessionUserId) return;
    model.flatratePresentationSessionUserId = String(sessionUserId);
}

export function readSelectedConversation(appInstance, roomKey) {
    const key = roomKey == null ? '' : String(roomKey);
    if (!key || !appInstance || !appInstance.chat) return null;

    const sessionUserId = presentationSessionUserId(appInstance);
    if (!sessionUserId) return null;

    const state = appInstance.chat;
    if (state.flatratePresentationSessionUserId !== sessionUserId) return null;

    const model = findStampedRoom(state, key, sessionUserId);
    if (model) {
        const normalized = normalizeLiveConversation(model);
        const title = normalized && normalized.title ? String(normalized.title).trim() : '';
        if (!normalized || normalized.isPublic !== true || !title || title === key) return null;
        return {
            kind: 'live',
            sourceId: key,
            roomKey: key,
            key,
            title,
            privacy: 'public',
            isPublic: true,
            presentationTrusted: true,
            sessionUserId,
            liveUserCount: null,
        };
    }

    const result = state.flatrateExactRoomResult;
    if (result && result.status === 'unavailable' && result.roomKey === key && result.sessionUserId === sessionUserId) {
        return {
            unavailable: true,
            kind: 'live',
            sourceId: key,
            sessionUserId,
        };
    }

    return null;
}

function findStampedRoom(state, key, sessionUserId) {
    const chats = Array.isArray(state.chats) ? state.chats : [];
    return chats.find((chat) => roomKeyOf(chat) === key && chat && chat.flatratePresentationSessionUserId === sessionUserId) || null;
}
