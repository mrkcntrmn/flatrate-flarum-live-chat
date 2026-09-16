import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const SECRET = 'SECRET_MESSAGE_BODY_006UI';

function read(rel) {
  return readFileSync(join(ROOT, rel), 'utf8');
}

test('ChatMessage uses one canonical author for avatar and nickname', () => {
  const src = read('js/src/forum/components/ChatMessage.js');
  const group = read('js/src/forum/components/ChatMessageGroup.js');
  assert.match(src, /authorForPresentation\s*\(\)\s*\{/);
  assert.match(src, /own && this\.isMessagesV2\(\) && app\.session\.user/);
  assert.match(group, /authorForPresentation/);
  assert.match(group, /avatar\(author/);
  assert.match(group, /username\(author\)/);
  // Forbidden split-identity pattern for the same V2 self message.
  assert.doesNotMatch(src, /avatar\(app\.session\.user\)[\s\S]*username\(this\.model\.user\(\)\)/);
  assert.doesNotMatch(src, /username\(this\.model\.user\(\)\)/);
  assert.match(src, /String\(author\.id\(\)\) === String\(actor\.id\(\)\)/);
});

test('V2 own nickname remains visible alongside avatar', () => {
  const less = read('resources/less/forum/ChatViewport.less');
  assert.match(less, /\.ChatViewport\.ChatViewport--messagesV2[\s\S]*\.ChatMessageGroup--own/);
  assert.match(less, /\.ChatMessageGroup-name/);
  assert.match(less, /\.ChatMessageGroup-avatar/);
  // Must not force-hide own nickname anymore.
  assert.doesNotMatch(
    less,
    /\.message-wrapper--own[\s\S]*a\.name,[\s\S]*\.name[\s\S]*display:\s*none\s*!important/
  );
  assert.ok(
    !/ChatMessageGroup-avatar\s*\{[^}]*display:\s*none/.test(less),
    'own avatar must remain visible'
  );
});

test('ChatPreview and LiveChatsPage never render last-message body', () => {
  const preview = read('js/src/forum/components/ChatPreview.js');
  assert.match(preview, /componentMetaLine/);
  assert.doesNotMatch(preview, /lastMessage\.message\(\)/);
  assert.doesNotMatch(preview, /formatTextPreview/);
  assert.doesNotMatch(preview, /componentTextPreview/);
  assert.doesNotMatch(preview, /senderName/);
  assert.ok(!preview.includes(SECRET));

  const page = read('js/src/forum/components/LiveChatsPage.js');
  assert.match(page, /LiveChatsPage-meta/);
  assert.doesNotMatch(page, /last\.message\?\.\(\)/);
  assert.doesNotMatch(page, /LiveChatsPage-preview/);
  assert.ok(!page.includes(SECRET));
});

test('normalizeLiveConversation still uses created_at only for activity', () => {
  const src = read('js/src/forum/utils/normalizeLiveConversation.js');
  assert.match(src, /last\.created_at/);
  assert.doesNotMatch(src, /last\.message/);
  assert.doesNotMatch(src, /preview|snippet|excerpt|lastMessageText/);
});
