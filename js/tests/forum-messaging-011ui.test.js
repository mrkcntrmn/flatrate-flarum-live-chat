import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');

function read(rel) {
  return readFileSync(join(ROOT, rel), 'utf8');
}

test('FORUM-MESSAGING-011UI: V2 grouped path exposes staff moderation via moderate permission', () => {
  const message = read('js/src/forum/components/ChatMessage.js');
  assert.match(message, /v2GroupedContent\s*\(/);
  assert.match(message, /canModerateMessage\s*\(/);
  assert.match(message, /moderationDropdown\s*\(/);
  assert.match(message, /ChatMessage-groupedRow/);
  assert.match(message, /flatrate-live-chat\.permissions\.moderate/);
  assert.match(message, /dropdownDelete/);
  assert.match(message, /message\.actions\.remove/);
  assert.doesNotMatch(message, /tech_\*/);
  assert.doesNotMatch(message, /isAdmin\s*\(/);

  const groupedFnStart = message.indexOf('v2GroupedContent() {');
  const groupedFnEnd = message.indexOf('v2OwnContent(author) {');
  const groupedFn = message.slice(groupedFnStart, groupedFnEnd);
  assert.match(groupedFn, /canModerateMessage/);
  assert.match(groupedFn, /moderationDropdown/);
  assert.match(groupedFn, /model\.id\(\)/);
  assert.doesNotMatch(groupedFn, /editDropDown/);
  assert.doesNotMatch(groupedFn, /dropdownHide/);
  assert.doesNotMatch(groupedFn, /actions\.hide/);

  const modFnStart = message.indexOf('moderationDropdown() {');
  const modFn = message.slice(modFnStart, modFnStart + 900);
  assert.match(modFn, /dropdownDelete/);
  assert.match(modFn, /fas fa-trash-alt/);
  assert.match(modFn, /actions\.remove/);
  assert.doesNotMatch(modFn, /dropdownEditStart/);
  assert.doesNotMatch(modFn, /dropdownResend/);
  assert.doesNotMatch(modFn, /dropdownHide/);
});

test('FORUM-MESSAGING-011UI: staff menu is permission-gated not member-visible by default', () => {
  const message = read('js/src/forum/components/ChatMessage.js');
  const predicateStart = message.indexOf('canModerateMessage() {');
  const predicate = message.slice(predicateStart, predicateStart + 280);
  assert.match(predicate, /app\.forum\.attribute\(\s*'flatrate-live-chat\.permissions\.moderate'\s*\)/);
  assert.doesNotMatch(predicate, /session\.user/);
  assert.doesNotMatch(predicate, /username/);
  assert.doesNotMatch(predicate, /groups?\(/);

  // Ordinary members only see the menu when the forum attribute is true.
  assert.match(message, /canModerate && this\.model\.id\(\)/);
});

test('FORUM-MESSAGING-011UI: Remove message locale + DELETE path wiring', () => {
  const locale = read('resources/locale/en.yaml');
  assert.match(locale, /\n\s+remove:\s*Remove message\n/);

  const state = read('js/src/forum/states/ChatState.js');
  assert.match(state, /case 'dropdownDelete':/);
  assert.match(state, /this\.deleteChatMessage\(model,\s*true\)/);
  assert.match(state, /model\.delete\(\)/);

  const handler = read('src/Commands/DeleteMessageHandler.php');
  assert.match(handler, /assertCanModerate\(\$actor\)/);
});

test('FORUM-MESSAGING-011UI: grouped row mirrors own/incoming without bubble-styling the menu', () => {
  const less = read('resources/less/forum/ChatViewport.less');
  assert.match(less, /\.ChatMessage-groupedRow\s*\{[\s\S]*display:\s*flex/);
  assert.match(
    less,
    /\.ChatMessageGroup:not\(\.ChatMessageGroup--own\)[\s\S]*\.ChatMessage-groupedRow[\s\S]*flex-direction:\s*row/
  );
  assert.match(
    less,
    /\.ChatMessageGroup--own[\s\S]*\.ChatMessage-groupedRow[\s\S]*flex-direction:\s*row-reverse/
  );
  assert.match(less, /\.ChatMessage-moderation\s*\{/);
  assert.match(less, /\.ChatMessage-moderationToggle\s*\{/);
  // Menu sits beside the bubble, not nested inside .message { ... } bubble chrome.
  assert.doesNotMatch(less, /\.message\s*\{[^}]*\.ChatMessage-moderation/);
});

test('FORUM-MESSAGING-011UI: generic avatar initials centered; photos keep object-fit cover', () => {
  const less = read('resources/less/forum/ChatViewport.less');
  assert.match(
    less,
    /\.ChatMessageGroup-avatar\s*\{[\s\S]*display:\s*flex[\s\S]*align-items:\s*center[\s\S]*justify-content:\s*center/
  );
  assert.match(
    less,
    /\.ChatMessageGroup-avatar > span,[\s\S]*align-items:\s*center[\s\S]*justify-content:\s*center/
  );
  assert.match(less, /\.ChatMessageGroup-avatar img\s*\{[\s\S]*object-fit:\s*cover/);
});
