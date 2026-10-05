import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { BRAND_BOARD_KEYS, brandRoomHref } from '../src/forum/brandBoardCatalog.js';
import { createLiveBoardProvider } from '../src/forum/liveBoardProvider.js';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');

function catalogBrandKeys() {
  const catalog = JSON.parse(readFileSync(join(ROOT, 'resources/room-catalog.json'), 'utf8'));
  return catalog.rooms
    .filter((room) => room.scopeType === 'board')
    .map((room) => room.scopeKey);
}

test('brand catalog is a 45-key bijection with the room catalog', () => {
  const fromCatalog = catalogBrandKeys();
  assert.equal(fromCatalog.length, 45);
  assert.equal(BRAND_BOARD_KEYS.length, 45);
  assert.deepEqual([...BRAND_BOARD_KEYS].sort(), [...fromCatalog].sort());
  assert.equal(brandRoomHref('alfa-romeo'), '/messages/live/alfa-romeo-live');
  assert.equal(brandRoomHref('genesis'), '/messages/live/genesis-live');
  assert.equal(brandRoomHref('not-a-brand'), null);
});

function harness({ preview = true, connected = true } = {}) {
  const requests = [];
  let payload = { available: true, liveUserCount: 1 };
  const timers = [];
  const app = {
    session: { user: { id: 2 } },
    forum: {
      attribute(name) {
        if (name === 'flatrate-live-chat.brand_live_admin_preview_available') return preview;
        if (name === 'flatrate-live-chat.realtime.connect') return connected;
        if (name === 'apiUrl') return 'https://forum.example';
        return null;
      },
    },
    request(options) {
      requests.push(options);
      return Promise.resolve(payload);
    },
    flatrateLiveRealtime: {
      acquire() {
        throw new Error('board preview must not acquire realtime');
      },
    },
  };
  const provider = createLiveBoardProvider({
    app,
    pollMs: 1000,
    setInterval(fn, ms) {
      const id = timers.length + 1;
      timers.push({ id, fn, ms });
      return id;
    },
    clearInterval(id) {
      const index = timers.findIndex((timer) => timer.id === id);
      if (index >= 0) timers.splice(index, 1);
    },
    redraw() {},
  });
  return {
    provider,
    requests,
    timers,
    setPayload(next) {
      payload = next;
    },
  };
}

test('provider stays closed without admin preview and for unknown keys', () => {
  const closed = harness({ preview: false });
  assert.equal(closed.provider.available('ford'), false);
  assert.equal(closed.provider.href('ford'), null);
  const open = harness();
  assert.equal(open.provider.available('ford'), true);
  assert.equal(open.provider.available('not-a-brand'), false);
  assert.equal(open.provider.href('ford'), '/messages/live/ford-live');
  assert.equal(open.provider.href('range-rover'), '/messages/live/range-rover-live');
  assert.equal(open.provider.href('alfa-romeo'), '/messages/live/alfa-romeo-live');
  assert.equal(open.provider.href('nope'), null);
});

test('count is positive, zero, or unknown and only the active board is polled', async () => {
  const { provider, requests, timers, setPayload } = harness();
  assert.equal(await provider.activate('ford'), true);
  assert.equal(provider.liveCount('ford'), 1);
  assert.equal(requests[0].body.roomKey, 'ford-live');
  assert.equal(provider.activePollCount(), 1);
  assert.equal(timers.length, 1);

  setPayload({ available: true, liveUserCount: 0 });
  await timers[0].fn();
  assert.equal(provider.liveCount('ford'), 0);

  setPayload({ available: true, liveUserCount: null });
  await timers[0].fn();
  assert.equal(provider.liveCount('ford'), null);

  setPayload({ available: true, liveUserCount: 4 });
  assert.equal(await provider.activate('toyota'), true);
  assert.equal(provider.activeBoardKey(), 'toyota');
  assert.equal(provider.liveCount('ford'), null);
  assert.equal(provider.liveCount('toyota'), 4);
  assert.equal(provider.activePollCount(), 1);
  assert.equal(timers.length, 1);
  assert.equal(requests.at(-1).body.roomKey, 'toyota-live');

  provider.deactivate('toyota');
  assert.equal(provider.activeBoardKey(), null);
  assert.equal(provider.activePollCount(), 0);
  assert.equal(timers.length, 0);
  assert.equal(provider.liveCount('toyota'), null);
});

test('board preview source does not acquire persistent or conversation presence', () => {
  const source = readFileSync(join(ROOT, 'js/src/forum/liveBoardProvider.js'), 'utf8');
  assert.doesNotMatch(source, /persistent_user_live/);
  assert.doesNotMatch(source, /active_conversation/);
  assert.doesNotMatch(source, /\.acquire\(/);
  assert.doesNotMatch(source, /flatrateLiveRealtime/);
});
