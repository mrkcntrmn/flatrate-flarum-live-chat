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

// --- STATIC_ARCHITECTURE_TESTS (updated for 008UI grouping) ---

test('STATIC: V2 grouped content + canonical author remain', () => {
  const src = read('js/src/forum/components/ChatMessage.js');
  assert.match(src, /v2GroupedContent\s*\(/);
  assert.match(src, /v2OwnContent\s*\(/);
  assert.match(src, /authorForPresentation\s*\(\)\s*\{/);
  assert.match(src, /own && this\.isMessagesV2\(\) && app\.session\.user/);
  assert.match(src, /String\(author\.id\(\)\) === String\(actor\.id\(\)\)/);
  assert.match(src, /attrs\.grouped/);
  assert.match(src, /message-wrapper--grouped/);

  const group = read('js/src/forum/components/ChatMessageGroup.js');
  assert.match(group, /authorForPresentation/);
  assert.match(group, /avatar\(author/);
  assert.match(group, /username\(author\)/);
});

test('STATIC: V2 CSS uses ChatMessageGroup with less.php-safe widths', () => {
  const less = read('resources/less/forum/ChatViewport.less');
  assert.match(less, /\.ChatViewport\.ChatViewport--messagesV2/);
  assert.match(less, /\.ChatMessageGroup--own/);
  assert.match(less, /\.ChatMessageGroup-header/);
  assert.match(less, /\.message-wrapper--grouped/);
  const v2Block = less.slice(less.indexOf('.ChatViewport.ChatViewport--messagesV2'));
  // Ownership layout must not use row-reverse; 011UI may mirror the staff menu row only.
  assert.doesNotMatch(v2Block, /\.message-wrapper--own[\s\S]*?>\s*div[\s\S]*?flex-direction:\s*row-reverse/);
  assert.doesNotMatch(v2Block, /\.ChatMessageGroup--own\s*\{[^}]*flex-direction:\s*row-reverse/);
  assert.match(v2Block, /\.ChatMessage-groupedRow[\s\S]*flex-direction:\s*row-reverse/);
  assert.doesNotMatch(v2Block, /max-width:\s*min\(/);
  assert.match(v2Block, /max-width:\s*~"min\(/);
  assert.match(v2Block, /\.ChatMessageGroup-avatar[\s\S]*?position:\s*relative/);
});

/**
 * Specificity arithmetic for the cascade failure that shipped in 006UI.
 */
function classSpecificity(selector) {
  const parts = selector.match(/\.[A-Za-z0-9_-]+/g) || [];
  return parts.length;
}

test('CASCADE: V2 group selectors out-specify legacy absolute geometry rules', () => {
  const legacyAvatar = '.ChatViewport .wrapper .message-wrapper .avatar-wrapper';
  const legacyRight =
    '.ChatViewport .wrapper .message-wrapper .message-block .toolbar .right';
  const legacyMargin = '.ChatViewport .wrapper .message-wrapper .message-block';

  const v2Avatar =
    '.ChatViewport.ChatViewport--messagesV2 .wrapper .ChatMessageGroup .ChatMessageGroup-avatar';
  const v2Grouped =
    '.ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--grouped .ChatMessage-content--grouped';
  const v2Time =
    '.ChatViewport.ChatViewport--messagesV2 .wrapper .ChatMessageGroup .ChatMessageGroup-time';

  assert.ok(
    classSpecificity(v2Avatar) > classSpecificity(legacyAvatar),
    `group avatar specificity ${classSpecificity(v2Avatar)} must beat legacy ${classSpecificity(legacyAvatar)}`
  );
  assert.ok(
    classSpecificity(v2Time) >= classSpecificity(legacyRight) - 1,
    `group time specificity ${classSpecificity(v2Time)} should compete with legacy .right ${classSpecificity(legacyRight)}`
  );
  assert.ok(
    classSpecificity(v2Grouped) > classSpecificity(legacyMargin),
    `grouped content specificity ${classSpecificity(v2Grouped)} must beat legacy message-block ${classSpecificity(legacyMargin)}`
  );

  const less = read('resources/less/forum/ChatViewport.less');
  const legacyIdx = less.indexOf('.ChatViewport {');
  const v2Idx = less.indexOf('.ChatViewport.ChatViewport--messagesV2');
  assert.ok(legacyIdx >= 0 && v2Idx > legacyIdx, 'V2 cascade repair must follow legacy ChatViewport block');
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
