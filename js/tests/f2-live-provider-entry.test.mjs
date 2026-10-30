#!/usr/bin/env node
/**
 * F2 forum entry must register app.flatRateMessagingSources.live and boot ChatState.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const indexF2 = readFileSync(join(root, 'js/src/forum/index-f2.js'), 'utf8');
const provider = readFileSync(join(root, 'js/src/forum/registerLiveMessagingProvider.js'), 'utf8');
const webpack = readFileSync(join(root, 'js/webpack.config.js'), 'utf8');
const extendF2 = readFileSync(join(root, 'extend-f2.php'), 'utf8');
const forumF2Entry = readFileSync(join(root, 'js/forum-f2.js'), 'utf8');

assert.match(indexF2, /registerLiveMessagingProvider\s*\(\s*\)/);
assert.match(indexF2, /app\.store\.models\.chats\s*=/);
assert.match(indexF2, /chat_pivot/);
assert.match(indexF2, /extend\(Application\.prototype,\s*'mount'/);
assert.match(indexF2, /new ChatState\s*\(/);
assert.match(indexF2, /apiFetchChats\s*\(/);
assert.doesNotMatch(indexF2, /addLiveChatsNavigation\s*\(/);
assert.doesNotMatch(indexF2, /registerLiveMainProvider\s*\(/);
assert.doesNotMatch(indexF2, /startLiveMainProvider\s*\(/);

assert.match(provider, /app\.flatRateMessagingSources\.live\s*=|flatRateMessagingSources\.live\s*=/);
assert.match(provider, /createLiveMessagingProvider/);

assert.match(forumF2Entry, /index-f2/);
assert.match(webpack, /forum-f2/);
// Webpack remaps F1 short imports for F2's flarum.reg.get externals path.
assert.match(webpack, /F1_TO_F2_REQUEST/);
assert.match(webpack, /function remapFlarumRequest\s*\(/);
assert.match(webpack, /flarum\/Model['"]:\s*['"]flarum\/common\/Model['"]/);
assert.match(webpack, /flarum\/components\/Search['"]:\s*['"]flarum\/forum\/components\/Search['"]/);
assert.match(webpack, /flarum\/helpers\/avatar['"]:\s*['"]flarum\/common\/components\/Avatar['"]/);
assert.match(webpack, /flarum\/helpers\/icon['"]:\s*['"]flarum\/common\/components\/Icon['"]/);
assert.match(webpack, /flarumRegistryExternal/);
assert.match(extendF2, /js\/dist\/forum-f2\.js/);
assert.match(extendF2, /Frontend\('forum'\)/);

console.error('[PASS] f2-live-provider-entry');
