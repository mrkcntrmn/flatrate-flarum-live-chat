/**
 * Collapse a sender's optimistic chatmessage with the authoritative row
 * that shares its persisted server id.
 *
 * Identity is the persisted message id only. Text, time, author, and room
 * are never used as a dedupe key, so two intentional identical sends remain
 * two messages.
 */

function persistedIdOf(model) {
    if (!model || typeof model.id !== 'function') return null;
    const id = model.id();
    if (id == null || id === '' || id === 0 || id === '0') return null;
    return id;
}

function storeModelFor(postedModel, persistedId) {
    const store = postedModel && postedModel.store;
    if (!store || typeof store.getById !== 'function') return null;
    const type = (postedModel.data && postedModel.data.type) || 'chatmessages';
    return store.getById(type, String(persistedId)) || store.getById(type, persistedId) || null;
}

function clearStaleZeroIndex(postedModel, survivor) {
    const store = postedModel && postedModel.store;
    const type = (postedModel.data && postedModel.data.type) || 'chatmessages';
    const bucket = store && store.data && store.data[type];
    if (!bucket || bucket['0'] !== postedModel || postedModel === survivor) return;
    delete bucket['0'];
}

/**
 * @param {{ chatmessages: object[] }} state
 * @param {object} postedModel optimistic model after its POST response was applied
 * @returns {object} the single model that should remain for this persisted id
 */
export function reconcilePostedChatMessage(state, postedModel) {
    const persistedId = persistedIdOf(postedModel);
    if (persistedId == null) return postedModel;

    const list = Array.isArray(state.chatmessages) ? state.chatmessages : [];
    const matches = list.filter((candidate) => candidate && typeof candidate.id === 'function' && candidate.id() == persistedId);
    const storeModel = storeModelFor(postedModel, persistedId);

    let survivor = postedModel;
    if (storeModel && storeModel !== postedModel) {
        survivor = storeModel;
    } else {
        const other = matches.find((candidate) => candidate !== postedModel);
        if (other) survivor = other;
    }

    if (survivor !== postedModel) {
        if (postedModel.isNeedToFlash) survivor.isNeedToFlash = true;
        survivor.isTimedOut = false;
        survivor.isEditing = false;
        survivor.exists = true;
    }

    const drop = new Set(matches.filter((candidate) => candidate !== survivor));
    if (postedModel !== survivor) drop.add(postedModel);

    state.chatmessages = list.filter((candidate) => !drop.has(candidate));
    if (!state.chatmessages.includes(survivor)) state.chatmessages.push(survivor);

    clearStaleZeroIndex(postedModel, survivor);

    const chat = typeof survivor.chat === 'function' ? survivor.chat() : null;
    if (chat && typeof chat.pushData === 'function') {
        const last = typeof chat.last_message === 'function' ? chat.last_message() : null;
        if (!last || drop.has(last) || last === postedModel) {
            chat.pushData({ relationships: { last_message: survivor } });
        }
    }

    return survivor;
}

/**
 * Success path shared by ChatState.postChatMessage.
 * Applies the POST payload, then reconciles by persisted id.
 */
export function applyPostedChatMessage(state, model, responseData) {
    model.pushData(responseData);
    model.exists = true;
    model.isTimedOut = false;
    model.isNeedToFlash = true;
    model.isEditing = false;

    const survivor = reconcilePostedChatMessage(state, model);
    const chat = typeof survivor.chat === 'function' ? survivor.chat() : null;
    if (chat && typeof chat.pushData === 'function') {
        chat.pushData({ relationships: { last_message: survivor } });
    }

    return survivor;
}
