import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Flarum serves tracked js/dist/forum.js (see extend.php), not js/src.
 * Marker checks catch SOURCE_CORRECT_DIST_STALE release failures.
 *
 * CI also runs `npm run build` then `git diff --exit-code -- js/dist`.
 */
const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const DIST = join(ROOT, 'js/dist/forum.js');

test('tracked forum dist exists and is registered for Flarum forum frontend', () => {
  assert.equal(existsSync(DIST), true);
  const extend = readFileSync(join(ROOT, 'extend.php'), 'utf8');
  assert.match(extend, /Extend\\Frontend\('forum'\)/);
  assert.match(extend, /js\/dist\/forum\.js/);
});

test('tracked forum dist embeds Live presence-stats provider path', () => {
  const dist = readFileSync(DIST, 'utf8');
  assert.match(dist, /presence-stats/);
  assert.match(dist, /liveUserCount/);
  assert.match(dist, /flatrate-live-chat\/realtime\/presence-stats/);
});

test('tracked forum dist embeds MAIN Live provider contract', () => {
  const dist = readFileSync(DIST, 'utf8');
  assert.match(dist, /flatRateLiveMain/);
  assert.match(dist, /flatrate:general-live-presence:v1/);
  assert.match(dist, /persistent_user_live/);
  assert.match(dist, /active_conversation/);
  assert.match(dist, /messages\/live\/community-general-live/);
});
