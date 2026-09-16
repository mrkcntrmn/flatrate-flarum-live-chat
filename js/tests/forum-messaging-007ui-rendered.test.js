import test from 'node:test';
import assert from 'node:assert/strict';
import { writeFileSync, mkdtempSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import {
  assertOwnRowGeometry,
  buildOwnRowFixtureHtml,
  measureOwnRows,
} from './forum-messaging-007ui-geometry.mjs';

function resolveChromiumExecutable() {
  const candidates = [
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH,
    process.env.CHROMIUM_PATH,
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/snap/bin/chromium',
  ].filter(Boolean);
  return candidates.find((p) => existsSync(p)) || null;
}

/**
 * RENDERED_007UI_GEOMETRY — real browser layout at 353px.
 * Uses Playwright + system Chromium when available.
 */
test('RENDERED: 353px own short+long geometry reserves avatar lane', async (t) => {
  let playwright;
  try {
    playwright = await import('playwright');
  } catch {
    t.skip('playwright not installed — run npm i -D playwright in js/ for rendered geometry');
    return;
  }

  const executablePath = resolveChromiumExecutable();
  const launchOptions = executablePath
    ? { headless: true, executablePath }
    : { headless: true };

  let browser;
  try {
    browser = await playwright.chromium.launch(launchOptions);
  } catch (err) {
    t.skip(`chromium unavailable for rendered geometry: ${err.message}`);
    return;
  }

  const dir = mkdtempSync(join(tmpdir(), '007ui-'));
  const shortPath = join(dir, 'short.html');
  const longPath = join(dir, 'long.html');
  writeFileSync(shortPath, buildOwnRowFixtureHtml({ body: 'Test', width: 353 }), 'utf8');
  writeFileSync(
    longPath,
    buildOwnRowFixtureHtml({
      body: '002REL provider-stage live smoke 1789447322618 https://example.com/very/long/path/token',
      width: 353,
    }),
    'utf8'
  );

  try {
    for (const [label, file] of [
      ['short', shortPath],
      ['long', longPath],
    ]) {
      const page = await browser.newPage({ viewport: { width: 353, height: 800 } });
      await page.goto(pathToFileURL(file).href);
      const measured = await page.evaluate((fnSrc) => {
        // eslint-disable-next-line no-new-func
        const measureOwnRows = new Function(`return (${fnSrc})`)();
        return measureOwnRows(document, window);
      }, measureOwnRows.toString());

      assert.ok(measured.length >= 2, `${label}: expected two own rows`);
      const first = measured[0];
      assert.equal(first.avatarPosition, 'relative', `${label}: avatar must not remain absolute`);
      assert.ok(first.avatarWidth >= 26, `${label}: avatar width`);
      assertOwnRowGeometry(first, assert);

      assertOwnRowGeometry(
        {
          ...first,
          nextRowRect: measured[1].rowRect,
        },
        assert
      );

      const covered = await page.evaluate(() => {
        const avatar = document.querySelector('.avatar-wrapper');
        const r = avatar.getBoundingClientRect();
        const el = document.elementFromPoint((r.left + r.right) / 2, (r.top + r.bottom) / 2);
        return {
          className: el && el.className,
          inAvatar: !!(el && (el.closest('.avatar-wrapper') === avatar || avatar.contains(el))),
        };
      });
      assert.equal(covered.inAvatar, true, `${label}: avatar center must not be covered by bubble text`);
      await page.close();
    }
  } finally {
    await browser.close();
  }
});
