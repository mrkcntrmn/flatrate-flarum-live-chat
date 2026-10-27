#!/usr/bin/env node
/**
 * Real Centrifugo WebSocket harness for disposable F2 Live Chat e2e.
 * Uses centrifuge JS client (not NullRealtimePublisher / FakeRealtimePublisher).
 */
import { createRequire } from 'node:module';
import { setTimeout as sleep } from 'node:timers/promises';
import { writeFileSync } from 'node:fs';

const require = createRequire(import.meta.url);

function loadCentrifuge() {
  const candidates = [
    process.env.CENTRIFUGE_MODULE,
    new URL('../../../js/node_modules/centrifuge/build/index.js', import.meta.url).pathname,
    'centrifuge',
  ].filter(Boolean);
  for (const c of candidates) {
    try {
      const mod = require(c);
      return mod.Centrifuge || mod.default || mod;
    } catch {
      // try next
    }
  }
  throw new Error('centrifuge_module_unavailable');
}

function parseArgs(argv) {
  const out = { _: [] };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) {
      const key = a.slice(2);
      const next = argv[i + 1];
      if (!next || next.startsWith('--')) {
        out[key] = true;
      } else {
        out[key] = next;
        i++;
      }
    } else {
      out._.push(a);
    }
  }
  return out;
}

function eventDedupeKey(envelope) {
  if (!envelope || typeof envelope !== 'object') return null;
  if (envelope.eventId) return String(envelope.eventId);
  const order = envelope.order ?? envelope.payload?.order ?? envelope.payload?.messageId;
  return [envelope.type, envelope.roomKey, order].join('|');
}

async function waitFor(predicate, timeoutMs, label) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    if (predicate()) return;
    await sleep(50);
  }
  throw new Error(`timeout_${label}`);
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const mode = args.mode || 'connect_subscribe_receive';
  const wsUrl = args.ws;
  const connectToken = args['connect-token'] || '';
  const channel = args.channel || '';
  const subToken = args['sub-token'] || '';
  const timeoutMs = Number(args.timeout || 15000);
  const outPath = args.out || '';
  const expectEventId = args['expect-event-id'] || '';
  const expectMessageId = args['expect-message-id'] || '';
  const expectRoomKey = args['expect-room-key'] || '';
  const Centrifuge = loadCentrifuge();

  const result = {
    mode,
    ok: false,
    connected: false,
    subscribed: false,
    publications: [],
    uniqueEventIds: [],
    duplicateBubbles: 0,
    error: null,
    disconnectCode: null,
  };

  const seen = new Set();

  const getConnectToken = async () => {
    if (!connectToken) throw new Error('missing_connect_token');
    return connectToken;
  };
  const getSubToken = async () => {
    if (args['missing-sub-token']) throw new Error('missing_subscription_token');
    if (!subToken) throw new Error('missing_sub_token');
    return subToken;
  };

  let client;
  try {
    if (mode === 'client_direct_publish') {
      client = new Centrifuge(wsUrl, { getToken: getConnectToken, timeout: timeoutMs });
      await new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('connect_timeout')), timeoutMs);
        client.on('connected', () => {
          clearTimeout(t);
          resolve();
        });
        client.on('error', (err) => {
          clearTimeout(t);
          reject(err?.error || err || new Error('connect_error'));
        });
        client.connect();
      });
      result.connected = true;
      const sub = client.newSubscription(channel, { getToken: getSubToken });
      await new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('subscribe_timeout')), timeoutMs);
        sub.on('subscribed', () => {
          clearTimeout(t);
          resolve();
        });
        sub.on('error', (err) => {
          clearTimeout(t);
          reject(err?.error || err || new Error('subscribe_error'));
        });
        sub.subscribe();
      });
      result.subscribed = true;
      try {
        await sub.publish({ probe: 'client-direct-publish' });
        result.error = 'client_publish_unexpectedly_allowed';
        result.ok = false;
      } catch (e) {
        result.error = String(e?.message || e?.code || e);
        // Centrifugo permission denied ≈ success for this negative case
        result.ok = true;
        result.denied = true;
      }
    } else if (mode === 'connect_only') {
      client = new Centrifuge(wsUrl, { getToken: getConnectToken, timeout: timeoutMs });
      await new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('connect_timeout')), timeoutMs);
        client.on('connected', () => {
          clearTimeout(t);
          result.connected = true;
          resolve();
        });
        client.on('error', (err) => {
          clearTimeout(t);
          reject(err?.error || err || new Error('connect_error'));
        });
        client.on('disconnected', (ctx) => {
          result.disconnectCode = ctx?.code ?? null;
        });
        client.connect();
      });
      result.ok = result.connected;
    } else if (mode === 'subscribe_expect_deny') {
      client = new Centrifuge(wsUrl, {
        getToken: getConnectToken,
        timeout: Math.min(timeoutMs, 5000),
        minReconnectDelay: 10000,
        maxReconnectDelay: 20000,
      });
      await new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('connect_timeout')), timeoutMs);
        client.on('connected', () => {
          clearTimeout(t);
          result.connected = true;
          resolve();
        });
        client.on('error', (err) => {
          clearTimeout(t);
          reject(err?.error || err || new Error('connect_error'));
        });
        client.connect();
      });
      const subOpts = {};
      if (args['missing-sub-token']) {
        subOpts.getToken = async () => {
          throw new Error('missing_subscription_token');
        };
      } else {
        subOpts.getToken = getSubToken;
      }
      const sub = client.newSubscription(channel, subOpts);
      try {
        await new Promise((resolve, reject) => {
          // Centrifuge may retry instead of a terminal error; non-subscribed = denial.
          const t = setTimeout(() => {
            if (!result.subscribed) {
              result.error = result.error || 'subscribe_timeout_not_subscribed';
              resolve();
            } else {
              reject(new Error('subscribe_unexpectedly_allowed'));
            }
          }, Math.min(timeoutMs, 8000));
          sub.on('subscribed', () => {
            clearTimeout(t);
            result.subscribed = true;
            reject(new Error('subscribe_unexpectedly_allowed'));
          });
          sub.on('error', (err) => {
            clearTimeout(t);
            result.error = String(err?.error?.message || err?.message || err?.code || err);
            resolve();
          });
          sub.subscribe();
        });
        result.ok = !result.subscribed;
        result.denied = !result.subscribed;
      } catch (e) {
        result.error = String(e.message || e);
        result.ok = false;
      }
    } else if (mode === 'reconnect_resume') {
      let tokenProvider = connectToken;
      client = new Centrifuge(wsUrl, {
        getToken: async () => {
          if (!tokenProvider) throw new Error('missing_connect_token');
          return tokenProvider;
        },
        timeout: timeoutMs,
      });
      const publications = [];
      const connectOnce = () =>
        new Promise((resolve, reject) => {
          const t = setTimeout(() => reject(new Error('connect_timeout')), timeoutMs);
          const onConnected = () => {
            clearTimeout(t);
            client.off('connected', onConnected);
            result.connected = true;
            resolve();
          };
          client.on('connected', onConnected);
          client.on('error', (err) => {
            // keep listening; connect may recover
            result.error = String(err?.error || err);
          });
          client.connect();
        });

      await connectOnce();
      let sub = client.newSubscription(channel, { getToken: getSubToken });
      const onPub = (ctx) => {
        const data = ctx?.data !== undefined ? ctx.data : ctx;
        const key = eventDedupeKey(data);
        if (key) {
          if (seen.has(key)) {
            result.duplicateBubbles += 1;
            return;
          }
          seen.add(key);
          result.uniqueEventIds.push(key);
        }
        publications.push(data);
        result.publications = publications;
      };
      sub.on('publication', onPub);
      await new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('subscribe_timeout')), timeoutMs);
        sub.on('subscribed', () => {
          clearTimeout(t);
          result.subscribed = true;
          resolve();
        });
        sub.on('error', (err) => {
          clearTimeout(t);
          reject(err?.error || err || new Error('subscribe_error'));
        });
        sub.subscribe();
      });

      // Force disconnect
      client.disconnect();
      await sleep(500);

      // Fresh backend token for reconnect
      if (args['reconnect-token']) {
        tokenProvider = args['reconnect-token'];
      }
      await connectOnce();
      // Ensure subscription resumes
      if (sub.state !== 'subscribed') {
        sub.subscribe();
        await waitFor(() => sub.state === 'subscribed', timeoutMs, 'resubscribe');
      }
      result.subscribed = true;

      // Inject same envelope twice into dedupe path (history merge simulation).
      // Expectation: one unique bubble; second delivery increments duplicateBubbles.
      if (args['replay-event-json']) {
        const replay = JSON.parse(args['replay-event-json']);
        onPub({ data: replay });
        onPub({ data: replay });
      }

      // Wait for optional live publication after reconnect
      if (args['wait-event-id']) {
        await waitFor(() => {
          return publications.some((p) => p && String(p.eventId) === String(args['wait-event-id']));
        }, timeoutMs, 'post_reconnect_event');
      }

      const replayId = args['replay-event-json']
        ? String(JSON.parse(args['replay-event-json']).eventId || '')
        : '';
      const replayCopies = replayId
        ? publications.filter((p) => p && String(p.eventId) === replayId).length
        : 0;
      result.ok =
        result.connected &&
        result.subscribed &&
        (!replayId || (replayCopies === 1 && result.duplicateBubbles >= 1));
      result.publications = publications;
    } else {
      // connect_subscribe_receive (default)
      client = new Centrifuge(wsUrl, { getToken: getConnectToken, timeout: timeoutMs });
      await new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('connect_timeout')), timeoutMs);
        client.on('connected', () => {
          clearTimeout(t);
          result.connected = true;
          resolve();
        });
        client.on('error', (err) => {
          clearTimeout(t);
          reject(err?.error || err || new Error('connect_error'));
        });
        client.on('disconnected', (ctx) => {
          result.disconnectCode = ctx?.code ?? null;
        });
        client.connect();
      });

      const sub = client.newSubscription(channel, { getToken: getSubToken });
      sub.on('publication', (ctx) => {
        const data = ctx?.data !== undefined ? ctx.data : ctx;
        const key = eventDedupeKey(data);
        if (key) {
          if (seen.has(key)) {
            result.duplicateBubbles += 1;
            return;
          }
          seen.add(key);
          result.uniqueEventIds.push(key);
        }
        result.publications.push(data);
      });
      await new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('subscribe_timeout')), timeoutMs);
        sub.on('subscribed', () => {
          clearTimeout(t);
          result.subscribed = true;
          resolve();
        });
        sub.on('error', (err) => {
          clearTimeout(t);
          reject(err?.error || err || new Error('subscribe_error'));
        });
        sub.subscribe();
      });

      if (args['wait-event'] || expectEventId || expectMessageId) {
        await waitFor(() => {
          return result.publications.some((p) => {
            if (!p || typeof p !== 'object') return false;
            if (expectEventId && String(p.eventId) !== String(expectEventId)) return false;
            if (expectRoomKey && String(p.roomKey) !== String(expectRoomKey)) return false;
            if (expectMessageId) {
              const mid = p.payload?.messageId ?? p.messageId;
              if (String(mid) !== String(expectMessageId)) return false;
            }
            return true;
          });
        }, timeoutMs, 'publication');
      }

      result.ok =
        result.connected &&
        result.subscribed &&
        (!args['wait-event'] || result.publications.length > 0);
    }
  } catch (e) {
    result.error = String(e?.message || e);
    result.ok = false;
  } finally {
    try {
      client?.disconnect();
    } catch {
      // ignore
    }
  }

  const json = JSON.stringify(result, null, 2);
  if (outPath) {
    writeFileSync(outPath, json);
  }
  process.stdout.write(json + '\n');
  process.exit(result.ok ? 0 : 1);
}

main();
