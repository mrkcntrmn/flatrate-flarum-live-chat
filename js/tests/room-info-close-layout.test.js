const assert = require('assert');
const fs = require('fs');
const path = require('path');

const less = fs.readFileSync(path.join(__dirname, '../../resources/less/forum/ChatModal.less'), 'utf8');

const ruleStart = less.indexOf('@media @phone, (max-height: 500px)');
assert.ok(ruleStart > 0, 'phone and short-viewport ChatModal rule exists');
const rule = less.slice(ruleStart);

assert.ok(rule.includes('.ChatModal.Modal'), 'positioning is scoped to ChatModal');
assert.ok(rule.includes('.ModalManager:has(> .ChatModal)'), 'only this dialog stops manager scrolling');
assert.equal(rule.includes('top: 0 !important'), false, 'phone close transition still owns top');
assert.ok(rule.includes('min-height: 0 !important'), 'content is not forced to 100vh');
assert.ok(rule.includes('max-height: 100dvh'), 'dialog fits the dynamic viewport');
assert.ok(rule.includes('.ChatModal .Modal-body'), 'body is the scroll container');
assert.ok(rule.includes('overflow-y: auto'), 'long room info remains scrollable');
assert.ok(rule.includes('.ChatModal .ButtonsPadding'), 'spacer change is scoped');
assert.ok(rule.includes('height: 16px'), 'phone spacer does not keep the 350px gap');
assert.equal(rule.includes('html {'), false);
assert.equal(rule.replace(/\.ChatModal \.Modal-content/g, '').includes('.Modal-content'), false);

const desktopSpacer = less.slice(0, ruleStart);
assert.ok(desktopSpacer.includes('height: 350px'), 'desktop spacer remains outside the phone query');

console.log('room-info-close-layout: ok');
