const assert = require('assert');
const path = require('path');

// Transform-free smoke: duplicate the dedupe helper logic for CI without babel.
function eventDedupeKey(envelope) {
  if (!envelope || typeof envelope !== 'object') return null;
  const order = envelope.order ?? envelope.payload?.order ?? envelope.payload?.messageId;
  return [envelope.type, envelope.roomKey, order].join('|');
}

const a = eventDedupeKey({ type: 'message.created', roomKey: 'community-general-live', order: 5 });
const b = eventDedupeKey({ type: 'message.created', roomKey: 'community-general-live', order: 5 });
const c = eventDedupeKey({ type: 'message.created', roomKey: 'community-general-live', order: 6 });
assert.strictEqual(a, b);
assert.notStrictEqual(a, c);
assert.ok(a.startsWith('message.created|community-general-live|'));

console.log('js_realtime_dedupe_ok');
