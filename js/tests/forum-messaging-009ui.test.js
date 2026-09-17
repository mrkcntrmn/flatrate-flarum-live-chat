const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const root = join(__dirname, '../..');
const less = readFileSync(join(root, 'resources/less/forum/MessagesBubbleStyle.less'), 'utf8');
const forumLess = readFileSync(join(root, 'resources/less/forum.less'), 'utf8');
const groupSource = readFileSync(join(root, 'js/src/forum/components/ChatMessageGroup.js'), 'utf8');

assert.match(forumLess, /@import\s+"forum\/MessagesBubbleStyle\.less";/);
assert.match(less, /\.ChatMessageGroup--own[\s\S]*?\.ChatMessageGroup-identity\s*\{\s*display:\s*none;/);
assert.match(less, /grid-template-columns:\s*minmax\(0, 1fr\)\s+auto\s+minmax\(0, 1fr\)/);
assert.match(less, /\.ChatMessageGroup-time[\s\S]*?justify-self:\s*center;[\s\S]*?text-align:\s*center;/);
assert.match(less, /message-wrapper--grouped \.message\s*\{[\s\S]*?background:\s*#e9e9eb;[\s\S]*?color:\s*#111111;/);
assert.match(less, /\.ChatMessageGroup--own \.message-wrapper\.message-wrapper--grouped \.message\s*\{[\s\S]*?background:\s*#0a84ff;[\s\S]*?color:\s*#ffffff;/);
assert.match(less, /\.ChatMessageGroup--own[\s\S]*?\.ChatMessageGroup-messages[\s\S]*?padding-right:\s*0;/);
assert.match(groupSource, /className="ChatMessageGroup-identity"/);
assert.match(groupSource, /className="ChatMessageGroup-time"/);
assert.doesNotMatch(less, /read-receipt|message-read|fa-check/i);

console.log('FORUM-MESSAGING-009UI live bubble presentation contract: PASS');
