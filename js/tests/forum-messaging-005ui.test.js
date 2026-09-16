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
  assert.match(src, /own && app\.session\.user \? app\.session\.user : this\.model\.user\(\)/);
  // Incoming path still uses message author when not own.
  assert.match(src, /this\.model\.user\(\)/);
});

test('V2 own layout targets ChatMessage-row, not anonymous child div', () => {
  const less = read('resources/less/forum/ChatViewport.less');
  assert.match(less, /\.ChatViewport--messagesV2[\s\S]*\.message-wrapper--own[\s\S]*\.ChatMessage-row/);
  assert.match(less, /\.ChatMessage-row[\s\S]*flex-direction:\s*row-reverse/);
  assert.doesNotMatch(less, /\.message-wrapper--own\s*>\s*div\s*\{/);
  assert.match(less, /\.message-wrapper--own[\s\S]*a\.name,[\s\S]*\.name[\s\S]*display:\s*none/);
  assert.match(less, /\.message-block[\s\S]*max-width:\s*82%/);
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
