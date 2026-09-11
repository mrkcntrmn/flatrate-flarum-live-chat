import assert from 'assert';
import fs from 'fs';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

async function loadLifecycle() {
  const srcPath = path.join(__dirname, '../src/forum/utils/chatMessagesFetchLifecycle.js');
  return import(pathToFileURL(srcPath).href);
}

function makeViewport() {
  return {
    loading: false,
    loadingQueries: {},
    messagesFetched: false,
  };
}

async function main() {
  const { runChatMessagesFetch, startInitialHistoryFetch, settleInitialHistoryFetch } = await loadLifecycle();

  // Existing UX source regressions retained
  const chatStateSrc = fs.readFileSync(path.join(__dirname, '../src/forum/states/ChatState.js'), 'utf8');
  const viewportSrc = fs.readFileSync(path.join(__dirname, '../src/forum/components/ChatViewport.js'), 'utf8');
  const indexSrc = fs.readFileSync(path.join(__dirname, '../src/forum/index.js'), 'utf8');
  assert.ok(chatStateSrc.includes('runChatMessagesFetch'));
  assert.ok(!/apiFetchChatMessages[\s\S]*loadingQueries\[query\] = false/.test(chatStateSrc));
  assert.ok(viewportSrc.includes('startInitialHistoryFetch'));
  assert.ok(viewportSrc.includes('settleInitialHistoryFetch'));
  assert.ok(!viewportSrc.includes('chatIsShown'));
  assert.ok(!indexSrc.includes('ChatFrame'));
  assert.ok(fs.existsSync(path.join(__dirname, 'realtime-client.test.js')));
  assert.ok(fs.existsSync(path.join(__dirname, 'check-unreaded-gate.test.js')));
  console.log('EXISTING_REALTIME_TEST_RETAINED=true');
  console.log('EXISTING_UNREAD_GATE_TEST_RETAINED=true');

  // nonempty success
  {
    const viewport = makeViewport();
    const inserted = [];
    const message = { id: () => 'm1' };
    let finds = 0;
    const result = await runChatMessagesFetch({
      viewport,
      query: undefined,
      findMessages: async () => {
        finds += 1;
        return [message];
      },
      insertMessage: (m) => inserted.push(m),
      notifyMessage: () => {},
      options: {},
      redraw: () => {},
    });
    assert.strictEqual(finds, 1);
    assert.strictEqual(result.length, 1);
    assert.strictEqual(viewport.loading, false);
    assert.strictEqual(Object.prototype.hasOwnProperty.call(viewport.loadingQueries, 'undefined'), false);
    assert.deepStrictEqual(inserted, [message]);
    console.log('NONEMPTY_FETCH_UNLOCK_TEST=PASS');
  }

  // empty success — primary regression
  {
    const viewport = makeViewport();
    const inserted = [];
    const result = await runChatMessagesFetch({
      viewport,
      query: undefined,
      findMessages: async () => [],
      insertMessage: (m) => inserted.push(m),
      notifyMessage: () => {},
      options: {},
      redraw: () => {},
    });
    assert.deepStrictEqual(result, []);
    assert.strictEqual(viewport.loading, false);
    assert.strictEqual(Object.keys(viewport.loadingQueries).length, 0);
    assert.strictEqual(inserted.length, 0);
    console.log('EMPTY_FETCH_UNLOCK_TEST=PASS');
  }

  // rejection
  {
    const viewport = makeViewport();
    const err = new Error('test-fetch-fail');
    let rejected = false;
    try {
      await runChatMessagesFetch({
        viewport,
        query: '2026-01-01T00:00:00.000Z',
        findMessages: async () => {
          throw err;
        },
        insertMessage: () => {},
        notifyMessage: () => {},
        options: {},
        redraw: () => {},
      });
    } catch (e) {
      rejected = e === err;
    }
    assert.ok(rejected);
    assert.strictEqual(viewport.loading, false);
    assert.strictEqual(Object.keys(viewport.loadingQueries).length, 0);
    console.log('FAILED_FETCH_UNLOCK_TEST=PASS');
  }

  // second fetch after empty
  {
    const viewport = makeViewport();
    const inserted = [];
    let finds = 0;
    const message = { id: () => 'm2' };
    await runChatMessagesFetch({
      viewport,
      query: undefined,
      findMessages: async () => {
        finds += 1;
        return [];
      },
      insertMessage: (m) => inserted.push(m),
      notifyMessage: () => {},
      redraw: () => {},
    });
    assert.strictEqual(viewport.loading, false);
    await runChatMessagesFetch({
      viewport,
      query: undefined,
      findMessages: async () => {
        finds += 1;
        return [message];
      },
      insertMessage: (m) => inserted.push(m),
      notifyMessage: () => {},
      redraw: () => {},
    });
    assert.strictEqual(finds, 2);
    assert.strictEqual(inserted.length, 1);
    assert.strictEqual(viewport.loading, false);
    console.log('EMPTY_THEN_SECOND_FETCH_TEST=PASS');
  }

  // empty then realtime-style bounded refetch (same guard path)
  {
    const viewport = makeViewport();
    const inserted = [];
    const chats = [{ room_key: () => 'community-general-live', id: () => '169' }];
    const chat = chats[0];
    const fetch = (model, query, options = {}) =>
      runChatMessagesFetch({
        viewport: viewport,
        query,
        findMessages: async () => {
          if (Array.isArray(query) && query.length) {
            return [{ id: () => 'rt-1', isNeedToFlash: false }];
          }
          return [];
        },
        insertMessage: (m) => inserted.push(m),
        notifyMessage: () => {},
        options,
        redraw: () => {},
      });

    await fetch(chat, undefined);
    assert.strictEqual(viewport.loading, false);
    assert.strictEqual(Object.keys(viewport.loadingQueries).length, 0);

    // mirror handleFlatRateRealtime bounded call
    const envelope = { type: 'message.created', roomKey: 'community-general-live', order: 42 };
    const matched = chats.find((c) => c.room_key?.() === envelope.roomKey);
    assert.ok(matched);
    await fetch(matched, [envelope.order].filter(Boolean), { notify: true, withFlash: true });
    assert.strictEqual(inserted.length, 1);
    assert.strictEqual(inserted[0].isNeedToFlash, true);
    assert.strictEqual(viewport.loading, false);
    console.log('EMPTY_THEN_REALTIME_TEST=PASS');
  }

  // messagesFetched flags
  {
    const emptyState = { messagesFetched: false };
    assert.strictEqual(startInitialHistoryFetch(emptyState), true);
    settleInitialHistoryFetch(emptyState, { ok: true });
    assert.strictEqual(emptyState.messagesFetched, true);
    console.log('INITIAL_EMPTY_MESSAGES_FETCHED_TEST=PASS');

    const nonemptyState = { messagesFetched: false };
    assert.strictEqual(startInitialHistoryFetch(nonemptyState), true);
    settleInitialHistoryFetch(nonemptyState, { ok: true });
    assert.strictEqual(nonemptyState.messagesFetched, true);
    console.log('INITIAL_NONEMPTY_MESSAGES_FETCHED_TEST=PASS');

    const failState = { messagesFetched: false };
    assert.strictEqual(startInitialHistoryFetch(failState), true);
    settleInitialHistoryFetch(failState, { ok: false });
    assert.strictEqual(failState.messagesFetched, false);
    console.log('INITIAL_FAILURE_MESSAGES_FETCHED_TEST=PASS');

    // duplicate initial load guard
    const dup = { messagesFetched: false };
    assert.strictEqual(startInitialHistoryFetch(dup), true);
    assert.strictEqual(startInitialHistoryFetch(dup), false);
  }

  // stale lock poison would block second call — prove unlock clears loadingQueries key
  {
    const viewport = makeViewport();
    await runChatMessagesFetch({
      viewport,
      query: undefined,
      findMessages: async () => [],
      insertMessage: () => {},
      notifyMessage: () => {},
      redraw: () => {},
    });
    assert.ok(!('undefined' in viewport.loadingQueries));
    const second = runChatMessagesFetch({
      viewport,
      query: undefined,
      findMessages: async () => [{ id: () => 'x' }],
      insertMessage: () => {},
      notifyMessage: () => {},
      redraw: () => {},
    });
    assert.ok(second && typeof second.then === 'function');
    await second;
  }

  console.log('js_message_fetch_lifecycle_ok');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
