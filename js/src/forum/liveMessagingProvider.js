import { roomKeyOf } from './utils/liveChatPresentation.js';
import { normalizeLiveDirectory, unreadCountOf } from './utils/normalizeLiveConversation.js';

export function createLiveMessagingProvider(options = {}) {
    const getApp = () => options.app || app;
    let lastListed = [];
    let authorizedKeys = new Set();

    async function listConversations() {
        const a = getApp();
        const payload = await a.request({
            method: 'GET',
            url: a.forum.attribute('apiUrl') + '/flatrate-live-chat/live-chats',
        });
        const models = a.store.pushPayload(payload);
        lastListed = normalizeLiveDirectory(models);
        authorizedKeys = new Set(lastListed.map((row) => row.roomKey));
        return lastListed.slice();
    }

    function getUnreadTotal() {
        const a = getApp();
        const chats = a.chat && a.chat.chats;
        if (authorizedKeys.size && Array.isArray(chats) && chats.length) {
            let matched = 0;
            const sum = chats.reduce((acc, chat) => {
                const key = roomKeyOf(chat);
                if (!key || !authorizedKeys.has(key)) return acc;
                matched += 1;
                return acc + unreadCountOf(chat);
            }, 0);
            if (matched > 0) return sum;
        }
        return lastListed.reduce((sum, row) => sum + (Number(row.unreadCount) || 0), 0);
    }

    async function refresh() {
        const a = getApp();
        if (a.chat && typeof a.chat.apiFetchChats === 'function') {
            await a.chat.apiFetchChats();
        }
        await listConversations();
    }

    function headerOverflowItems({ key } = {}) {
        const a = getApp();
        const build = options.buildHeaderOverflowItems;
        if (typeof build !== 'function' || !a.chat) return null;

        // Route key is authoritative: never expose the previous room's actions
        // while Messages is selecting a different Live conversation.
        const requestedKey = key != null ? String(key) : null;
        let chat = null;

        if (requestedKey && Array.isArray(a.chat.chats)) {
            chat = a.chat.chats.find((candidate) => roomKeyOf(candidate) === requestedKey) || null;
        }

        if (!requestedKey && typeof a.chat.getCurrentChat === 'function') {
            chat = a.chat.getCurrentChat();
        }

        if (!chat) return null;
        return build(chat, a) || null;
    }

    return {
        schemaVersion: 1,
        kind: 'live',
        listConversations,
        getUnreadTotal,
        renderConversation: options.renderConversation || (({ key, context }) => null),
        headerOverflowItems,
        refresh,
    };
}
