/**
 * Presentation-only message grouping for Messages V2 Live chat.
 * FORUM-MESSAGING-008UI — does not mutate models or realtime semantics.
 */

export const GROUP_GAP_MS = 5 * 60 * 1000;

function isEventMessage(model) {
    if (!model) return false;
    const type = typeof model.type === 'function' ? model.type() : model.type;
    return !!type;
}

function authorIdOf(model) {
    if (!model) return null;
    const user = typeof model.user === 'function' ? model.user() : model.user;
    if (!user) return null;
    const id = typeof user.id === 'function' ? user.id() : user.id;
    return id != null ? String(id) : null;
}

function createdAtMs(model) {
    if (!model) return 0;
    const raw = typeof model.created_at === 'function' ? model.created_at() : model.created_at;
    if (!raw) return 0;
    if (raw instanceof Date) return raw.getTime();
    const t = new Date(raw).getTime();
    return Number.isFinite(t) ? t : 0;
}

function modelKey(model, index) {
    if (!model) return `idx-${index}`;
    if (typeof model.id === 'function' && model.id() != null) return String(model.id());
    if (model.id != null) return String(model.id);
    return `tmp-${index}-${createdAtMs(model)}`;
}

function isOwnAuthor(authorId, sessionUser) {
    if (!authorId || !sessionUser) return false;
    const sid = typeof sessionUser.id === 'function' ? sessionUser.id() : sessionUser.id;
    return sid != null && String(sid) === authorId;
}

/**
 * @param {Array} models ordered chat messages (history + optional optimistic preview)
 * @param {{ gapMs?: number, sessionUser?: object|null }} options
 * @returns {Array<{kind:'group', key:string, authorId:string|null, own:boolean, timestampModel:object, messages:object[]}|{kind:'event', key:string, model:object}>}
 */
export function groupChatMessages(models = [], { gapMs = GROUP_GAP_MS, sessionUser = null } = {}) {
    const items = [];
    let current = null;

    models.forEach((model, index) => {
        if (isEventMessage(model)) {
            if (current) {
                items.push(current);
                current = null;
            }
            items.push({
                kind: 'event',
                key: `event-${modelKey(model, index)}`,
                model,
            });
            return;
        }

        const authorId = authorIdOf(model);
        const at = createdAtMs(model);
        const canContinue =
            current && current.kind === 'group' && current.authorId != null && current.authorId === authorId && at - current.lastCreatedAt <= gapMs;

        if (canContinue) {
            current.messages.push(model);
            current.lastCreatedAt = at;
            return;
        }

        if (current) items.push(current);

        current = {
            kind: 'group',
            key: `group-${modelKey(model, index)}`,
            authorId,
            own: isOwnAuthor(authorId, sessionUser),
            timestampModel: model,
            messages: [model],
            lastCreatedAt: at,
        };
    });

    if (current) items.push(current);
    return items;
}

export function isEventMessageModel(model) {
    return isEventMessage(model);
}
