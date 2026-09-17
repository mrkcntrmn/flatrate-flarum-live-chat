import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const read = (rel) => readFileSync(join(ROOT, rel), 'utf8');

test('Messages Live provider requests count-only primary-room presence', () => {
  const src = read('js/src/forum/liveMessagingProvider.js');
  assert.match(src, /PRIMARY_ROOM_KEY/);
  assert.match(src, /flatrate-live-chat\.realtime\.connect/);
  assert.match(src, /\/flatrate-live-chat\/realtime\/presence-stats/);
  assert.match(src, /body:\s*\{ roomKey \}/);
  assert.match(src, /liveUserCount/);
  assert.match(src, /primaryIndex/);
});

test('Live provider exposes liveUserCount only when realtime is configured and fails soft', () => {
  const src = read('js/src/forum/liveMessagingProvider.js');
  assert.match(src, /fetchPrimaryPresenceCount/);
  assert.match(
    src,
    /if \(!a\.forum\.attribute\('flatrate-live-chat\.realtime\.connect'\)\) return null;/
  );
  assert.match(src, /catch \(e\) \{/);
  assert.match(src, /return null;/);
  assert.match(src, /rows\[primaryIndex\] = \{ \.\.\.rows\[primaryIndex\], liveUserCount: count \};/);
  // Room authorization remains list-driven; presence never invents rooms.
  assert.match(src, /authorizedKeys = new Set\(lastListed\.map/);
  assert.doesNotMatch(src, /\/api\/presence['"]/);
});

test('forum API exposes authorized roomKey-only presence stats', () => {
  const extend = read('extend.php');
  const controller = read('src/Api/Controllers/RealtimePresenceStatsController.php');
  assert.match(extend, /realtime\/presence-stats/);
  assert.match(controller, /roomKey/);
  assert.match(controller, /assertCanSubscribeRealtime/);
  assert.match(controller, /findByRoomKeyOrFail/);
  assert.match(controller, /channel_name/);
  assert.match(controller, /liveUserCount/);
  assert.doesNotMatch(controller, /presence\s*=>/);
});

test('server-side reader uses presence_stats and returns num_users only', () => {
  const reader = read('src/Realtime/CentrifugoPresenceStatsReader.php');
  assert.match(reader, /\/api\/presence_stats/);
  assert.match(reader, /num_users/);
  assert.doesNotMatch(reader, /\/api\/presence['"]/);
  assert.doesNotMatch(reader, /num_clients['"]\s*\]/);
});
