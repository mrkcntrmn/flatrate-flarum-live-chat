/**
 * FlatRate live-chat realtime client (Pusher Channels).
 * Realtime accelerates UI; HTTP history remains authoritative.
 * Fail-closed when forum attributes say connect=false.
 */

function eventDedupeKey(envelope) {
    if (!envelope || typeof envelope !== 'object') return null;
    const order = envelope.order ?? envelope.payload?.order ?? envelope.payload?.messageId;
    return [envelope.type, envelope.roomKey, order].join('|');
}

export default class FlatRateRealtimeClient {
    constructor(options = {}) {
        this.app = options.app || (typeof app !== 'undefined' ? app : null);
        this.Pusher = options.Pusher || null;
        this.onEvent = options.onEvent || (() => {});
        this.pusher = null;
        this.subscriptions = new Map();
        this.seen = new Set();
        this.maxSeen = 500;
        this.reconnectAttempts = 0;
    }

    forumAttr(key) {
        return this.app?.forum?.attribute?.(key);
    }

    isConfigured() {
        return !!this.forumAttr('flatrate-live-chat.realtime.connect') && !!this.forumAttr('flatrate-live-chat.realtime.key');
    }

    async connect() {
        if (!this.isConfigured()) {
            return false;
        }
        if (this.pusher) {
            return true;
        }
        const PusherLib = this.Pusher || (await this.loadPusher());
        if (!PusherLib) {
            return false;
        }
        const key = this.forumAttr('flatrate-live-chat.realtime.key');
        const cluster = this.forumAttr('flatrate-live-chat.realtime.cluster') || 'mt1';
        const authEndpoint = this.forumAttr('flatrate-live-chat.realtime.authEndpoint') || '/api/flatrate-live-chat/realtime/auth';

        this.pusher = new PusherLib(key, {
            cluster,
            forceTLS: true,
            authEndpoint,
            auth: {
                headers: this.authHeaders(),
            },
        });

        this.pusher.connection.bind('connected', () => {
            this.reconnectAttempts = 0;
        });
        this.pusher.connection.bind('unavailable', () => this.scheduleReconnect());
        this.pusher.connection.bind('failed', () => this.scheduleReconnect());
        return true;
    }

    authHeaders() {
        const headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
        const token = this.app?.session?.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
        if (token) {
            headers['X-CSRF-Token'] = token;
        }
        return headers;
    }

    async loadPusher() {
        if (typeof window !== 'undefined' && window.Pusher) {
            return window.Pusher;
        }
        try {
            // Explicit package dependency (not ambient flarum/pusher).
            const mod = require('pusher-js');
            return mod.default || mod;
        } catch (e) {
            return null;
        }
    }

    async subscribe(channelName) {
        if (!channelName || !channelName.startsWith('private-flatrate-live-')) {
            return null;
        }
        if (!(await this.connect())) {
            return null;
        }
        if (this.subscriptions.has(channelName)) {
            return this.subscriptions.get(channelName);
        }
        const channel = this.pusher.subscribe(channelName);
        channel.bind('flatrate.live', (data) => this.handleEnvelope(data));
        this.subscriptions.set(channelName, channel);
        return channel;
    }

    unsubscribe(channelName) {
        if (!this.pusher || !channelName) return;
        if (this.subscriptions.has(channelName)) {
            this.pusher.unsubscribe(channelName);
            this.subscriptions.delete(channelName);
        }
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
            if (this.pusher) {
                try {
                    this.pusher.connect();
                } catch (e) {
                    // fail closed — HTTP history remains authoritative
                }
            }
        }, delay);
    }

    disconnect() {
        this.unsubscribeAll();
        if (this.pusher) {
            this.pusher.disconnect();
            this.pusher = null;
        }
    }
}

export { eventDedupeKey };
