import assert from 'assert';
import fs from 'fs';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

async function loadProcessVisibleUnread() {
  const srcPath = path.join(__dirname, '../src/forum/utils/processVisibleUnread.js');
  const mod = await import(pathToFileURL(srcPath).href);
  return mod.processVisibleUnread;
}

function makeModel({ id = 'general', unreaded = 3 } = {}) {
  let unread = unreaded;
  return {
    id: () => id,
    unreaded: () => unread,
    pushAttributes: (attrs) => {
      if ('unreaded' in attrs) unread = attrs.unreaded;
    },
  };
}

function makeMessage({ id, offsetTop }) {
  return {
    id: () => id,
    isReaded: false,
    offsetTop,
  };
}

async function main() {
  const processVisibleUnread = await loadProcessVisibleUnread();

  // Source contract: checkUnreaded must not gate on legacy chatIsShown()
  const viewportSrc = fs.readFileSync(path.join(__dirname, '../src/forum/components/ChatViewport.js'), 'utf8');
  const checkFn = viewportSrc.match(/checkUnreaded\(\)\s*\{[\s\S]*?\n    \}/)?.[0] || '';
  assert.ok(checkFn.includes('processVisibleUnread'));
  assert.ok(!checkFn.includes('chatIsShown'));
  assert.ok(checkFn.includes('getCurrentChat()'));
  console.log('LEGACY_CHAT_IS_SHOWN_READ_GATE_PRESENT=false');

  // Upgrade hazard: beingShown=false must not block read when current room is mounted
  {
    const model = makeModel({ unreaded: 2 });
    const msg = makeMessage({ id: 'm1', offsetTop: 10 });
    const reads = [];
    const result = processVisibleUnread({
      wrapper: { scrollTop: 0, clientHeight: 500 },
      model,
      currentChat: model,
      messages: [msg],
      autoScroll: false,
      apiReadChat: (chat, anchor) => reads.push({ chat, anchor }),
      findMessageEl: () => ({ offsetTop: 10 }),
      // beingShown intentionally false / unused
      beingShown: false,
    });
    assert.strictEqual(result.gated, false);
    assert.strictEqual(result.processed, 1);
    assert.strictEqual(msg.isReaded, true);
    assert.strictEqual(reads.length, 1);
    assert.strictEqual(model.unreaded(), 1);
    console.log('PERSISTED_BEING_SHOWN_FALSE_TEST=PASS');
  }

  // Non-current room must not be marked read
  {
    const model = makeModel({ id: 'general', unreaded: 5 });
    const other = makeModel({ id: 'toyota', unreaded: 9 });
    const msg = makeMessage({ id: 'm2', offsetTop: 10 });
    const reads = [];
    const result = processVisibleUnread({
      wrapper: { scrollTop: 0, clientHeight: 500 },
      model,
      currentChat: other,
      messages: [msg],
      autoScroll: false,
      apiReadChat: () => reads.push(1),
      findMessageEl: () => ({ offsetTop: 10 }),
    });
    assert.strictEqual(result.gated, true);
    assert.strictEqual(result.processed, 0);
    assert.strictEqual(msg.isReaded, false);
    assert.strictEqual(reads.length, 0);
    assert.strictEqual(model.unreaded(), 5);
    console.log('NON_CURRENT_ROOM_NOT_MARKED_READ_TEST=PASS');
  }

  // Auto-scroll path clears unread for current room
  {
    const model = makeModel({ unreaded: 4 });
    const msg = makeMessage({ id: 'm3', offsetTop: 20 });
    const reads = [];
    processVisibleUnread({
      wrapper: { scrollTop: 0, clientHeight: 500 },
      model,
      currentChat: model,
      messages: [msg],
      autoScroll: true,
      apiReadChat: (chat, anchor) => reads.push({ chat, anchor }),
      findMessageEl: () => ({ offsetTop: 20 }),
    });
    assert.strictEqual(model.unreaded(), 0);
    assert.strictEqual(reads.length, 1);
    assert.ok(reads[0].anchor instanceof Date);
  }

  console.log('js_check_unreaded_gate_ok');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
