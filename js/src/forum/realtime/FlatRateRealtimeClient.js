/**
 * FlatRate live-chat realtime client (Centrifugo).
 * Realtime accelerates UI; HTTP history remains authoritative.
 * Fail-closed when forum attributes say connect=false.
 * Client publish is never enabled.
 */

function eventDedupeKey(envelope) {
    if (!envelope || typeof envelope !== 'object') return null;
    if (envelope.eventId) {
        return String(envelope.eventId);
    }
    // Legacy v1 fallback only.
    const order = envelope.order ?? envelope.payload?.order ?? envelope.payload?.messageId;
    return [envelope.type, envelope.roomKey, order].join('|');
}

function channelForRoomKey(roomKey) {
    if (!roomKey || typeof roomKey !== 'string') return null;
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(roomKey)) return null;
    return `$flatrate-live-${roomKey}`;
}

function roomKeyFromChannel(channel) {
    if (!channel || typeof channel !== 'string') return null;
    if (!channel.startsWith('$flatrate-live-')) return null;
    const roomKey = channel.slice('$flatrate-live-'.length);
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(roomKey)) return null;
    return roomKey;
}

export default class FlatRateRealtimeClient {
    constructor(options = {}) {
        this.app = options.app || (typeof app !== 'undefined' ? app : null);
        this.Centrifuge = options.Centrifuge || null;
        this.fetchImpl = options.fetchImpl || (typeof fetch !== 'undefined' ? fetch.bind(typeof window !== 'undefined' ? window : globalThis) : null);
        this.onEvent = options.onEvent || (() => {});
        this.client = null;
        this.subscriptions = new Map(); // roomKey -> Subscription
        this.seen = new Set();
        this.maxSeen = 500;
        this.reconnectAttempts = 0;
    }

    forumAttr(key) {
        return this.app?.forum?.attribute?.(key);
    }

    isConfigured() {
        return (
            !!this.forumAttr('flatrate-live-chat.realtime.connect') &&
            !!this.forumAttr('flatrate-live-chat.realtime.websocketUrl') &&
            this.forumAttr('flatrate-live-chat.realtime.transport') === 'CENTRIFUGO'
        );
    }

    async connect() {
        if (!this.isConfigured()) {
            return false;
        }
        if (this.client) {
            return true;
        }
        const CentrifugeLib = this.Centrifuge || (await this.loadCentrifuge());
        if (!CentrifugeLib) {
            return false;
        }
        const websocketUrl = this.forumAttr('flatrate-live-chat.realtime.websocketUrl');
        if (!websocketUrl) {
            return false;
        }

        this.client = new CentrifugeLib(websocketUrl, {
            getToken: () => this.fetchConnectToken(),
        });

        this.client.on('connected', () => {
            this.reconnectAttempts = 0;
        });
        this.client.on('disconnected', () => this.scheduleReconnect());
        this.client.on('error', () => this.scheduleReconnect());

        try {
            this.client.connect();
        } catch (e) {
            this.client = null;
            return false;
        }
        return true;
    }

    authHeaders() {
        const headers = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        };
        const token =
            this.app?.session?.csrfToken || (typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.content : null);
        if (token) {
            headers['X-CSRF-Token'] = token;
        }
        return headers;
    }

    async fetchConnectToken() {
        const endpoint = this.forumAttr('flatrate-live-chat.realtime.connectTokenEndpoint') || '/api/flatrate-live-chat/realtime/connect-token';
        const res = await this.postJson(endpoint, {});
        if (!res || !res.token) {
            throw new Error('connect_token_unavailable');
        }
        return res.token;
    }

    async fetchSubscriptionToken(roomKey) {
        const endpoint =
            this.forumAttr('flatrate-live-chat.realtime.subscriptionTokenEndpoint') || '/api/flatrate-live-chat/realtime/subscription-token';
        const res = await this.postJson(endpoint, { roomKey });
        if (!res || !res.token) {
            throw new Error('subscription_token_unavailable');
        }
        return res.token;
    }

    async postJson(endpoint, body) {
        if (!this.fetchImpl) {
            throw new Error('fetch_unavailable');
        }
        const response = await this.fetchImpl(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: this.authHeaders(),
            body: JSON.stringify(body || {}),
        });
        if (!response.ok) {
            throw new Error(`token_http_${response.status}`);
        }
        return response.json();
    }

    async loadCentrifuge() {
        if (typeof window !== 'undefined' && window.Centrifuge) {
            return window.Centrifuge;
        }
        try {
            const mod = require('centrifuge');
            return mod.Centrifuge || mod.default || mod;
        } catch (e) {
            return null;
        }
    }

    /**
     * Subscribe by roomKey (preferred). Also accepts a full `$flatrate-live-*` channel.
     */
    async subscribe(roomKeyOrChannel) {
        let roomKey = roomKeyOrChannel;
        if (typeof roomKeyOrChannel === 'string' && roomKeyOrChannel.startsWith('$flatrate-live-')) {
            roomKey = roomKeyFromChannel(roomKeyOrChannel);
        }
        const channel = channelForRoomKey(roomKey);
        if (!channel) {
            return null;
        }
        if (!(await this.connect())) {
            return null;
        }
        if (this.subscriptions.has(roomKey)) {
            return this.subscriptions.get(roomKey);
        }

        const sub = this.client.newSubscription(channel, {
            getToken: () => this.fetchSubscriptionToken(roomKey),
        });
        sub.on('publication', (ctx) => {
            const data = ctx && ctx.data !== undefined ? ctx.data : ctx;
            this.handleEnvelope(data);
        });
        try {
            sub.subscribe();
        } catch (e) {
            return null;
        }
        this.subscriptions.set(roomKey, sub);
        return sub;
    }

    unsubscribe(roomKeyOrChannel) {
        let roomKey = roomKeyOrChannel;
        if (typeof roomKeyOrChannel === 'string' && roomKeyOrChannel.startsWith('$flatrate-live-')) {
            roomKey = roomKeyFromChannel(roomKeyOrChannel);
        }
        if (!roomKey || !this.subscriptions.has(roomKey)) return;
        const sub = this.subscriptions.get(roomKey);
        try {
            sub.unsubscribe();
            if (typeof sub.removeAllListeners === 'function') {
                sub.removeAllListeners();
            }
        } catch (e) {
            // fail closed — HTTP history remains authoritative
        }
        this.subscriptions.delete(roomKey);
    }

    unsubscribeAll() {
        for (const name of [...this.subscriptions.keys()]) {
            this.unsubscribe(name);
        }
    }

    handleEnvelope(envelope) {
        const key = eventDedupeKey(envelope);
        if (key) {
            if (this.seen.has(key)) {
                return; // multi-tab / reconnect dedupe — no catastrophic dup
            }
            this.seen.add(key);
            if (this.seen.size > this.maxSeen) {
                const first = this.seen.values().next().value;
                this.seen.delete(first);
            }
        }
        this.onEvent(envelope);
    }

    scheduleReconnect() {
        this.reconnectAttempts += 1;
        const delay = Math.min(30000, 1000 * 2 ** Math.min(this.reconnectAttempts, 5));
        setTimeout(() => {
            if (this.client) {
                try {
                    this.client.connect();
                } catch (e) {
                    // fail closed — HTTP history remains authoritative
                }
            }
        }, delay);
    }

    disconnect() {
        this.unsubscribeAll();
        if (this.client) {
            try {
                this.client.disconnect();
            } catch (e) {
                // ignore
            }
            this.client = null;
        }
    }
}

export { eventDedupeKey, channelForRoomKey, roomKeyFromChannel };
