import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { groupChatMessages, GROUP_GAP_MS } from '../src/forum/utils/groupChatMessages.js';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');

function read(rel) {
  return readFileSync(join(ROOT, rel), 'utf8');
}

function user(id) {
  return {
    id: () => String(id),
  };
}

function msg({ id, authorId, at, type = 0 }) {
  return {
    id: () => id,
    type: () => type,
    user: () => user(authorId),
    created_at: () => new Date(at),
  };
}

const t0 = Date.parse('2026-09-16T10:00:00Z');

test('GROUP: same user consecutive within 5 minutes → one group', () => {
  const items = groupChatMessages(
    [
      msg({ id: 'a', authorId: '1', at: t0 }),
      msg({ id: 'b', authorId: '1', at: t0 + 60_000 }),
      msg({ id: 'c', authorId: '1', at: t0 + 120_000 }),
    ],
    { sessionUser: user('1') }
  );

  assert.equal(items.length, 1);
  assert.equal(items[0].kind, 'group');
  assert.equal(items[0].messages.length, 3);
  assert.equal(items[0].own, true);
  assert.equal(items[0].timestampModel.id(), 'a');
});

test('GROUP: author change → separate groups', () => {
  const items = groupChatMessages(
    [
      msg({ id: 'a', authorId: '1', at: t0 }),
      msg({ id: 'b', authorId: '2', at: t0 + 30_000 }),
      msg({ id: 'c', authorId: '1', at: t0 + 60_000 }),
    ],
    { sessionUser: user('1') }
  );

  assert.equal(items.length, 3);
  assert.deepEqual(
    items.map((i) => i.kind),
    ['group', 'group', 'group']
  );
  assert.equal(items[0].own, true);
  assert.equal(items[1].own, false);
  assert.equal(items[2].own, true);
});

test('GROUP: time gap > 5 minutes breaks group', () => {
  const items = groupChatMessages(
    [
      msg({ id: 'a', authorId: '1', at: t0 }),
      msg({ id: 'b', authorId: '1', at: t0 + GROUP_GAP_MS + 1 }),
    ],
    { sessionUser: user('1') }
  );

  assert.equal(items.length, 2);
  assert.equal(items[0].messages.map((m) => m.id()).join(','), 'a');
  assert.equal(items[1].messages.map((m) => m.id()).join(','), 'b');
});

test('GROUP: event message breaks groups', () => {
  const items = groupChatMessages(
    [
      msg({ id: 'a', authorId: '1', at: t0 }),
      msg({ id: 'e', authorId: '1', at: t0 + 10_000, type: 1 }),
      msg({ id: 'b', authorId: '1', at: t0 + 20_000 }),
    ],
    { sessionUser: user('1') }
  );

  assert.equal(items.length, 3);
  assert.equal(items[0].kind, 'group');
  assert.equal(items[1].kind, 'event');
  assert.equal(items[2].kind, 'group');
  assert.equal(items[0].messages.length, 1);
  assert.equal(items[2].messages.length, 1);
});

test('STATIC: ChatMessageGroup + viewport wiring', () => {
  const group = read('js/src/forum/components/ChatMessageGroup.js');
  assert.match(group, /ChatMessageGroup-header/);
  assert.match(group, /ChatMessageGroup-identity/);
  assert.match(group, /ChatMessageGroup-time/);
  assert.match(group, /ChatMessageGroup-avatar/);
  assert.match(group, /authorForPresentation/);
  assert.match(group, /grouped=\{true\}/);
  // Own header: name before avatar in identity when own
  assert.match(group, /group\.own \? \([\s\S]*ChatMessageGroup-name[\s\S]*avatarNode/);

  const viewport = read('js/src/forum/components/ChatViewport.js');
  assert.match(viewport, /groupChatMessages/);
  assert.match(viewport, /componentsChatMessageGroups/);
  assert.match(viewport, /ChatMessageGroup/);
  assert.match(viewport, /presentationVersion === 2/);

  const message = read('js/src/forum/components/ChatMessage.js');
  assert.match(message, /v2GroupedContent/);
  assert.match(message, /attrs\.grouped/);
  assert.match(message, /message-wrapper--grouped/);
  // V2 grouped path must not call editDropDown
  const groupedFnStart = message.indexOf('v2GroupedContent()');
  const groupedFnEnd = message.indexOf('v2OwnContent(author)');
  const groupedFn = message.slice(groupedFnStart, groupedFnEnd);
  assert.doesNotMatch(groupedFn, /editDropDown/);
});

test('STATIC: V2 LESS mirrors own header and has no per-message ellipsis contract', () => {
  const less = read('resources/less/forum/ChatViewport.less');
  assert.match(less, /\.ChatMessageGroup--own/);
  assert.match(less, /\.ChatMessageGroup-time/);
  assert.match(less, /\.ChatMessageGroup-identity/);
  assert.match(less, /order:\s*1/);
  assert.match(less, /order:\s*2/);
  assert.match(less, /\.message-wrapper--grouped/);
  // less.php-safe escaped min()
  assert.match(less, /max-width:\s*~"min\(/);
  assert.doesNotMatch(less, /max-width:\s*min\(/);
});

test('STATIC: V1 ellipsis path preserved on legacyContent', () => {
  const message = read('js/src/forum/components/ChatMessage.js');
  const legacyStart = message.indexOf('legacyContent(author) {');
  assert.ok(legacyStart >= 0);
  const legacy = message.slice(legacyStart, legacyStart + 1200);
  assert.match(legacy, /editDropDown/);
});
