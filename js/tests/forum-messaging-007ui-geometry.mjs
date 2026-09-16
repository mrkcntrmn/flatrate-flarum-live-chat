/**
 * FORUM-MESSAGING-007UI — reusable rendered-geometry helpers.
 * These assert browser rect contracts; they are not CSS-selector presence checks.
 */

export function intersects(a, b) {
  if (!a || !b) return false;
  return !(a.right <= b.left || a.left >= b.right || a.bottom <= b.top || a.top >= b.bottom);
}

export function inside(inner, outer, epsilon = 1) {
  if (!inner || !outer) return false;
  return (
    inner.left >= outer.left - epsilon &&
    inner.right <= outer.right + epsilon &&
    inner.top >= outer.top - epsilon &&
    inner.bottom <= outer.bottom + epsilon
  );
}

/**
 * Own-row geometry contract for Messages V2.
 * Intentional avatar/bubble-box overlap may be true; avatar/text must not intersect.
 */
export function assertOwnRowGeometry(rects, assert) {
  const {
    viewportRect,
    avatarRect,
    nameRect,
    timestampRect,
    bubbleRect,
    messageTextRect,
    rowRect,
    nextRowRect,
  } = rects;

  assert.ok(avatarRect.width >= 26, `avatar width ${avatarRect.width} < 26`);
  assert.ok(avatarRect.height >= 26, `avatar height ${avatarRect.height} < 26`);
  assert.ok(inside(avatarRect, viewportRect), 'avatar must stay inside viewport');
  assert.ok(inside(bubbleRect, viewportRect), 'bubble must stay inside viewport');
  assert.equal(intersects(avatarRect, messageTextRect), false, 'avatar must not cover message text');
  assert.equal(intersects(nameRect, timestampRect), false, 'nickname/timestamp must not collide');
  assert.equal(intersects(nameRect, bubbleRect), false, 'nickname must not collide with bubble');
  assert.equal(intersects(timestampRect, bubbleRect), false, 'timestamp must not collide with bubble');

  if (nextRowRect) {
    assert.equal(intersects(rowRect, nextRowRect), false, 'adjacent rows must not overlap');
    assert.ok(rowRect.bottom <= nextRowRect.top + 0.5, 'rows must stack with non-negative gap');
  }
}

/**
 * Build a minimal Messages V2 own-row fixture for browser layout tests.
 */
export function buildOwnRowFixtureHtml({ nickname = 'Wizard', body = 'Test', width = 353 } = {}) {
  return `<!doctype html>
<html>
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=${width}"/>
<style>
  :root { --heading-color:#111; --muted-color:#666; --primary-color:#2563eb; --control-bg:#e8e8e8; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 14px/1.4 system-ui, sans-serif; }
  .ChatViewport.ChatViewport--messagesV2 { width: ${width}px; overflow: hidden; }
  .ChatViewport.ChatViewport--messagesV2 .wrapper { overflow-x: hidden; width: 100%; }

  /* Legacy rules that previously won on specificity */
  .ChatViewport .wrapper .message-wrapper { display: block; padding: 8px 12px 8px 16px; position: relative; }
  .ChatViewport .wrapper .message-wrapper .avatar-wrapper { position: absolute; }
  .ChatViewport .wrapper .message-wrapper .avatar-wrapper .avatar { width: 26px; height: 26px; border-radius: 26px; }
  .ChatViewport .wrapper .message-wrapper .message-block { margin-left: 35px; position: relative; }
  .ChatViewport .wrapper .message-wrapper .message-block .toolbar { position: relative; }
  .ChatViewport .wrapper .message-wrapper .message-block .toolbar .right { display: inline; right: 0; position: absolute; }

  /* 007UI contract — must beat legacy */
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own {
    display: block; box-sizing: border-box; width: 100%; padding: 8px 12px; position: relative; text-align: right;
  }
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .ChatMessage-row--own {
    display: grid; grid-template-columns: minmax(0, 1fr) 28px; column-gap: 6px; align-items: end; width: 100%; min-width: 0;
  }
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .ChatMessage-content {
    grid-column: 1; min-width: 0; max-width: min(86%, 560px); justify-self: end;
    display: flex; flex-direction: column; align-items: flex-end; margin: 0;
  }
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .ChatMessage-meta {
    display: flex; align-items: baseline; justify-content: flex-end; flex-wrap: wrap; gap: 6px;
    width: 100%; min-width: 0; margin: 0 0 2px; position: relative;
  }
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .ChatMessage-meta .name {
    position: static; display: block; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600;
  }
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .ChatMessage-meta .timestamp {
    position: static; flex: 0 0 auto; display: block; white-space: nowrap; font-size: 12px;
  }
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .ChatMessage-actions {
    position: static; flex: 0 0 auto;
  }
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .avatar-wrapper {
    grid-column: 2; display: block; position: relative; width: 28px; height: 28px; min-width: 28px;
    align-self: end; overflow: visible; z-index: 2; margin: 0; transform: translateX(-2px);
  }
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .avatar-wrapper > span {
    display: block; width: 28px; height: 28px;
  }
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .avatar-wrapper .Avatar,
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .avatar-wrapper .avatar,
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .avatar-wrapper img {
    display: block; width: 28px; height: 28px; border-radius: 50%; object-fit: cover;
  }
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .message {
    width: fit-content; max-width: 100%; min-width: 0; text-align: left; padding: 8px 12px; border-radius: 14px;
    background: color-mix(in srgb, var(--primary-color) 18%, var(--control-bg)); overflow-wrap: anywhere;
  }
  .ChatViewport.ChatViewport--messagesV2 .wrapper .message-wrapper.message-wrapper--own .message .actualMessage {
    display: block; max-width: 100%; min-width: 0; overflow-wrap: anywhere;
  }
</style>
</head>
<body>
  <div class="ChatViewport ChatViewport--messagesV2">
    <div class="wrapper">
      <div class="message-wrapper message-wrapper--own" data-id="1">
        <div class="ChatMessage-row ChatMessage-row--own">
          <div class="ChatMessage-content">
            <div class="ChatMessage-meta">
              <a class="name">${nickname}:</a>
              <a class="timestamp">3 hours ago</a>
              <div class="ChatMessage-actions"></div>
            </div>
            <div class="message"><div class="actualMessage">${body}</div></div>
          </div>
          <a class="avatar-wrapper" href="#"><span><img class="Avatar avatar" alt="" width="28" height="28" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='28' height='28'%3E%3Ccircle cx='14' cy='14' r='14' fill='%23888'/%3E%3C/svg%3E"/></span></a>
        </div>
      </div>
      <div class="message-wrapper message-wrapper--own" data-id="2">
        <div class="ChatMessage-row ChatMessage-row--own">
          <div class="ChatMessage-content">
            <div class="ChatMessage-meta">
              <a class="name">${nickname}:</a>
              <a class="timestamp">2 hours ago</a>
              <div class="ChatMessage-actions"></div>
            </div>
            <div class="message"><div class="actualMessage">Second row</div></div>
          </div>
          <a class="avatar-wrapper" href="#"><span><img class="Avatar avatar" alt="" width="28" height="28" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='28' height='28'%3E%3Ccircle cx='14' cy='14' r='14' fill='%23888'/%3E%3C/svg%3E"/></span></a>
        </div>
      </div>
    </div>
  </div>
</body>
</html>`;
}

export function measureOwnRows(document, window) {
  const viewport = document.querySelector('.ChatViewport--messagesV2');
  const rows = [...document.querySelectorAll('.message-wrapper--own')];
  return rows.map((row, i) => {
    const avatar = row.querySelector('.avatar-wrapper');
    const name = row.querySelector('.name');
    const timestamp = row.querySelector('.timestamp');
    const bubble = row.querySelector('.message');
    const text = row.querySelector('.actualMessage');
    const next = rows[i + 1];
    return {
      viewportRect: viewport.getBoundingClientRect(),
      avatarRect: avatar.getBoundingClientRect(),
      nameRect: name.getBoundingClientRect(),
      timestampRect: timestamp.getBoundingClientRect(),
      bubbleRect: bubble.getBoundingClientRect(),
      messageTextRect: text.getBoundingClientRect(),
      rowRect: row.getBoundingClientRect(),
      nextRowRect: next ? next.getBoundingClientRect() : null,
      avatarPosition: window.getComputedStyle(avatar).position,
      avatarWidth: avatar.getBoundingClientRect().width,
    };
  });
}
