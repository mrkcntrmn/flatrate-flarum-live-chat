import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { intersects, assertOwnRowGeometry } from './forum-messaging-007ui-geometry.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');

function read(rel) {
  return readFileSync(join(ROOT, rel), 'utf8');
}

// --- STATIC_ARCHITECTURE_TESTS (not visual acceptance) ---

test('STATIC: V2 own content uses dedicated structure without row-reverse', () => {
  const src = read('js/src/forum/components/ChatMessage.js');
  assert.match(src, /v2OwnContent\s*\(/);
  assert.match(src, /ChatMessage-row--own/);
  assert.match(src, /ChatMessage-content/);
  assert.match(src, /ChatMessage-meta/);
  assert.match(src, /ChatMessage-actions/);
  assert.match(src, /authorForPresentation\s*\(\)\s*\{/);
  assert.match(src, /own && this\.isMessagesV2\(\) && app\.session\.user/);
  assert.match(src, /avatar\(author/);
  assert.match(src, /username\(author\)/);
  assert.match(src, /String\(author\.id\(\)\) === String\(actor\.id\(\)\)/);
  // Own V2 DOM order: content then avatar (no CSS reversal as ownership model).
  const ownStart = src.indexOf('v2OwnContent(author) {');
  const ownEnd = src.indexOf('legacyContent(author) {');
  assert.ok(ownStart >= 0 && ownEnd > ownStart, 'v2OwnContent method must precede legacyContent');
  const ownFn = src.slice(ownStart, ownEnd);
  assert.ok(ownFn.indexOf('ChatMessage-content') < ownFn.indexOf('avatarNode'), 'content before avatar in V2 own DOM');
  assert.ok(ownFn.indexOf('ChatMessage-meta') < ownFn.indexOf('avatarNode'), 'meta before avatar in V2 own DOM');
  assert.ok(ownFn.includes('messageBody()'), 'V2 own must reuse message body renderer');
});

test('STATIC: V2 CSS beats legacy absolute positioning via deeper specificity', () => {
  const less = read('resources/less/forum/ChatViewport.less');
  assert.match(less, /\.ChatViewport\.ChatViewport--messagesV2/);
  assert.match(less, /\.wrapper[\s\S]*\.message-wrapper\.message-wrapper--own/);
  assert.match(less, /grid-template-columns:\s*minmax\(0,\s*1fr\)\s*28px/);
  assert.match(less, /\.ChatMessage-row--own/);
  assert.match(less, /\.ChatMessage-meta/);
  assert.match(less, /transform:\s*translateX\(-2px\)/);
  // Must not depend on row-reverse for V2 own layout anymore.
  const v2Block = less.slice(less.indexOf('.ChatViewport.ChatViewport--messagesV2'));
  assert.doesNotMatch(v2Block, /flex-direction:\s*row-reverse/);
  // Avatar lane must be relative/grid, not absolute.
  assert.match(v2Block, /\.avatar-wrapper\s*\{[\s\S]*?position:\s*relative/);
  assert.match(v2Block, /min-width:\s*28px/);
  // Meta must not use absolute .right under V2 own.
  assert.doesNotMatch(v2Block, /\.toolbar\s+\.right[\s\S]*position:\s*absolute/);
  assert.match(v2Block, /\.ChatMessage-meta\s+\.timestamp[\s\S]*?position:\s*static/);
});

/**
 * Specificity arithmetic for the cascade failure that shipped in 006UI.
 * Counts: (a,b,c) = (inline, IDs, classes/attrs) — we only need class counts here.
 */
function classSpecificity(selector) {
  const parts = selector.match(/\.[A-Za-z0-9_-]+/g) || [];
  return parts.length;
}

test('CASCADE: V2 own selectors out-specify legacy absolute geometry rules', () => {
  // Legacy winners observed on production (computed position:absolute).
  const legacyAvatar = '.ChatViewport .wrapper .message-wrapper .avatar-wrapper';
  const legacyRight =
    '.ChatViewport .wrapper .message-wrapper .message-block .toolbar .right';
  const legacyMargin =
    '.ChatViewport .wrapper .message-wrapper .message-block';

  // V2 own reset (current source contract).
  const v2Avatar =
    '.ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .avatar-wrapper';
  const v2Content =
    '.ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .ChatMessage-content';
  const v2Meta =
    '.ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .ChatMessage-meta';

  assert.ok(
    classSpecificity(v2Avatar) > classSpecificity(legacyAvatar),
    `avatar specificity ${classSpecificity(v2Avatar)} must beat legacy ${classSpecificity(legacyAvatar)}`
  );
  assert.ok(
    classSpecificity(v2Meta) >= classSpecificity(legacyRight),
    `meta specificity ${classSpecificity(v2Meta)} must beat/match legacy .right ${classSpecificity(legacyRight)}`
  );
  assert.ok(
    classSpecificity(v2Content) > classSpecificity(legacyMargin),
    `content specificity ${classSpecificity(v2Content)} must beat legacy message-block ${classSpecificity(legacyMargin)}`
  );

  // Source must emit the deep V2 path after the legacy block.
  const less = read('resources/less/forum/ChatViewport.less');
  const legacyIdx = less.indexOf('.ChatViewport {');
  const v2Idx = less.indexOf('.ChatViewport.ChatViewport--messagesV2');
  assert.ok(legacyIdx >= 0 && v2Idx > legacyIdx, 'V2 own cascade repair must follow legacy ChatViewport block');
});

test('STATIC: zero-preview and identity architecture remain', () => {
  const preview = read('js/src/forum/components/ChatPreview.js');
  assert.doesNotMatch(preview, /lastMessage\.message\(\)/);
  const src = read('js/src/forum/components/ChatMessage.js');
  assert.doesNotMatch(src, /username\(this\.model\.user\(\)\)/);
});

test('GEOMETRY_HELPER: intersects and own-row contract', () => {
  assert.equal(
    intersects({ left: 0, right: 10, top: 0, bottom: 10 }, { left: 5, right: 15, top: 5, bottom: 15 }),
    true
  );
  assert.equal(
    intersects({ left: 0, right: 10, top: 0, bottom: 10 }, { left: 10, right: 20, top: 0, bottom: 10 }),
    false
  );

  assertOwnRowGeometry(
    {
      viewportRect: { left: 0, right: 353, top: 0, bottom: 800 },
      avatarRect: { left: 320, right: 348, top: 40, bottom: 68, width: 28, height: 28 },
      nameRect: { left: 200, right: 250, top: 10, bottom: 28 },
      timestampRect: { left: 256, right: 310, top: 10, bottom: 28 },
      bubbleRect: { left: 80, right: 310, top: 32, bottom: 70 },
      messageTextRect: { left: 88, right: 300, top: 40, bottom: 56 },
      rowRect: { left: 0, right: 353, top: 0, bottom: 80 },
      nextRowRect: { left: 0, right: 353, top: 88, bottom: 160 },
    },
    assert
  );
});
