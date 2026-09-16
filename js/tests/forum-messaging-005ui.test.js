import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');

function read(rel) {
  return readFileSync(join(ROOT, rel), 'utf8');
}

test('ChatViewport passes presentationVersion into ChatMessage', () => {
  const viewport = read('js/src/forum/components/ChatViewport.js');
  assert.match(viewport, /presentationVersion=\{presentationVersion\}/);
  assert.match(viewport, /const presentationVersion = this\.attrs\.presentationVersion/);
});

test('ChatMessage exposes explicit ChatMessage-row and ID-based own detection', () => {
  const src = read('js/src/forum/components/ChatMessage.js');
  assert.match(src, /className="ChatMessage-row"/);
  assert.match(src, /isOwnMessage\s*\(\)\s*\{/);
  assert.match(src, /String\(author\.id\(\)\) === String\(actor\.id\(\)\)/);
  assert.match(src, /'message-wrapper--own':\s*this\.isOwnMessage\(\)/);
  assert.match(src, /authorForPresentation\s*\(\)\s*\{/);
  assert.match(src, /own && this\.isMessagesV2\(\) && app\.session\.user/);
  assert.match(src, /presentationVersion === 2/);
  // Incoming / legacy path still falls back to message author.
  assert.match(src, /return messageAuthor/);
});

test('V2 own layout targets ChatMessage-row with visible own identity', () => {
  const less = read('resources/less/forum/ChatViewport.less');
  // 007UI: grid lane under dual ChatViewport class + .wrapper (beats legacy absolute).
  assert.match(less, /\.ChatViewport\.ChatViewport--messagesV2[\s\S]*\.message-wrapper\.message-wrapper--own/);
  assert.match(less, /\.ChatMessage-row--own[\s\S]*grid-template-columns:\s*~?"minmax\(0,\s*1fr\)\s*28px"/);
  assert.doesNotMatch(less, /\.message-wrapper--own\s*>\s*div\s*\{/);
  // 006UI: nickname is no longer force-hidden on own bubbles.
  assert.doesNotMatch(
    less,
    /\.message-wrapper--own[\s\S]*a\.name,[\s\S]*\.name[\s\S]*display:\s*none\s*!important/
  );
  assert.match(less, /\.ChatMessage-content[\s\S]*max-width:\s*~"min\(86%/);
});

test('directory overflow contributes Settings/Info without global sound toggles', () => {
  const util = read('js/src/forum/utils/chatDirectoryOverflowItems.js');
  assert.match(util, /liveSettings/);
  assert.match(util, /ChatEditModal/);
  assert.doesNotMatch(util, /toggleSound|toggleNotifications|liveSound|liveNotifications/);
  const register = read('js/src/forum/registerLiveMessagingProvider.js');
  assert.match(register, /buildDirectoryOverflowItems:\s*chatDirectoryOverflowItems/);
  const provider = read('js/src/forum/liveMessagingProvider.js');
  assert.match(provider, /directoryOverflowItems/);
});
