import { roomKeyOf } from './liveChatPresentation.js';
import { resetSelectedRoomPresenceCache, scheduleSelectedRoomPresence } from './selectedRoomPresence.js';
import { bindPresentationSession, noteExactRoomResult, presentationSessionUserId, stampAuthorizedRoom } from './selectedRoomPresentation.js';

export function isHiddenStaffPreviewRoom(model) {
    if (!model) return false;
    const visibility = typeof model.visibility === 'function' ? model.visibility() : model.visibility;
    const audience = typeof model.audience === 'function' ? model.audience() : model.audience;
    return visibility === 'hidden' || audience === 'staff-preview';
}

export function isDirectoryListedChat(chat) {
    return !chat || !chat.flatrateRouteHydratedOnly;
}

function findChatByRoomKey(roomKey) {
    if (!roomKey || !app.chat || !Array.isArray(app.chat.chats)) return null;
    return app.chat.chats.find((chat) => roomKeyOf(chat) === roomKey) || null;
}

/**
 * Keep one model per server id. Hidden staff-preview rooms hydrated from a
 * direct route stay out of the directory collection.
 */
export function rememberHydratedRoom(state, model) {
    if (!state || !model || typeof model.id !== 'function') return null;
    const existing = Array.isArray(state.chats) ? state.chats.find((chat) => String(chat.id()) === String(model.id())) : null;
    if (existing) {
        if (typeof state.ensureViewportState === 'function') state.ensureViewportState(existing);
        return existing;
    }
    if (isHiddenStaffPreviewRoom(model)) model.flatrateRouteHydratedOnly = true;
    state.addChat(model);
    return model;
}

function redraw() {
    if (typeof m !== 'undefined' && m.redraw) m.redraw();
}

/**
 * Select a routed live room from local state, or from one exact-room request.
 * The directory list is not required to contain the room.
 */
export function selectRoutedLiveRoom(view, roomKey) {
    view.ensureChat();
    const sessionUserId = presentationSessionUserId(app);
    const previousSessionUserId = app.chat ? app.chat.flatratePresentationSessionUserId : undefined;
    bindPresentationSession(app.chat, sessionUserId);
    if (previousSessionUserId !== undefined && previousSessionUserId !== sessionUserId) {
        resetSelectedRoomPresenceCache();
    }
    const selectionGeneration = ++view.selectionGeneration;
    if (view.lastSelectedKey !== roomKey) {
        view.releaseRoomView(view.lastSelectedKey);
    }
    view.lastSelectedKey = roomKey;
    scheduleSelectedRoomPresence(view, roomKey);

    if (!roomKey) {
        view.unavailable = true;
        view.selecting = false;
        view.releaseRoomView();
        return;
    }

    const match = findChatByRoomKey(roomKey);
    const trustedMatch = Boolean(sessionUserId && match && match.flatratePresentationSessionUserId === sessionUserId);
    if (match && (!sessionUserId || trustedMatch)) {
        view.unavailable = false;
        view.selecting = false;
        app.chat.setCurrentChat(match);
        view.holdRoomView(roomKey);
        if (trustedMatch) {
            noteExactRoomResult(app.chat, { status: 'ready', roomKey, sessionUserId });
        }
        return;
    }

    const fetchExact = app.chat && app.chat.apiFetchChatByRoomKey;
    if (typeof fetchExact !== 'function') {
        view.unavailable = true;
        view.selecting = false;
        view.releaseRoomView(roomKey);
        noteExactRoomResult(app.chat, { status: 'unavailable', roomKey, sessionUserId });
        return;
    }

    view.selecting = true;
    view.unavailable = false;
    noteExactRoomResult(app.chat, { status: 'pending', roomKey, sessionUserId });
    const sessionAtStart = sessionUserId;
    fetchExact
        .call(app.chat, roomKey)
        .then((found) => {
            if (selectionGeneration !== view.selectionGeneration || view.lastSelectedKey !== roomKey) return;
            if (!exactRoomResultCurrent(view, selectionGeneration, roomKey, sessionAtStart)) return;
            if (!found) {
                markUnavailable(view, roomKey, sessionAtStart);
                return;
            }
            stampAuthorizedRoom(found, sessionAtStart);
            noteExactRoomResult(app.chat, { status: 'ready', roomKey, sessionUserId: sessionAtStart });
            view.unavailable = false;
            app.chat.setCurrentChat(found);
            view.holdRoomView(roomKey);
            view.selecting = false;
            redraw();
        })
        .catch(() => {
            if (selectionGeneration !== view.selectionGeneration || view.lastSelectedKey !== roomKey) return;
            if (!exactRoomResultCurrent(view, selectionGeneration, roomKey, sessionAtStart)) return;
            markUnavailable(view, roomKey, sessionAtStart);
        });
}

function exactRoomResultCurrent(view, selectionGeneration, roomKey, sessionUserId) {
    if (selectionGeneration !== view.selectionGeneration || view.lastSelectedKey !== roomKey) return false;
    return presentationSessionUserId(app) === sessionUserId;
}

function markUnavailable(view, roomKey, sessionUserId) {
    view.unavailable = true;
    view.releaseRoomView(roomKey);
    if (app.chat.getCurrentChat && roomKeyOf(app.chat.getCurrentChat()) !== roomKey) {
        app.chat.setCurrentChat(null);
    }
    noteExactRoomResult(app.chat, { status: 'unavailable', roomKey, sessionUserId });
    view.selecting = false;
    redraw();
}
