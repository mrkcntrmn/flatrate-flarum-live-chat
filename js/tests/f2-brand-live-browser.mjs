import assert from 'node:assert/strict';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';

const { chromium, expect } = createRequire(process.env.PLAYWRIGHT_PACKAGE_JSON)('@playwright/test');
const fixture = JSON.parse(readFileSync(process.env.F2_AUTH_FILE, 'utf8'));
const base = fixture.baseUrl;
assert.equal(new URL(base).hostname, '127.0.0.1', 'Synthetic loopback fixture only');
const evidence = process.env.F2_EVIDENCE_DIR;
mkdirSync(evidence, { recursive: true });
const browser = await chromium.launch({ headless: true });
const contexts = [], results = [], errors = [];
async function actor(role, width = 1440, height = 900) {
    const c = await browser.newContext({ viewport: { width, height } });
    contexts.push(c);
    if (role) await c.addCookies([{ name: 'flarum_remember', value: fixture.users[role].token, domain: '127.0.0.1', path: '/' }]);
    const p = await c.newPage();
    p.on('pageerror', e => errors.push({ role, error: e.message }));
    return p;
}
async function visit(p, path) {
    const r = await p.goto(base + path, { waitUntil: 'networkidle' });
    assert.equal(r.status(), 200, path);
}
async function record(name, fn) {
    await fn();
    results.push({ name, status: 'PASS' });
    writeFileSync(resolve(evidence, 'brand-live-browser-results.json'), JSON.stringify({ results, errors }, null, 2));
    console.log('PASS ' + name);
}
async function noOverflow(p) {
    const x = await p.evaluate(() => ({ w: innerWidth, doc: document.documentElement.scrollWidth }));
    assert.ok(x.doc <= x.w + 1, JSON.stringify(x));
}
const fordLink = p => p.getByRole('link', { name: 'Open Ford Public Live chat', exact: true });
try {
    const beta = await actor('member'), admin = await actor('admin'), ordinary = await actor('peer'), mod = await actor('outsider'), guest = await actor(null);
    await record('compiled F2 beta board pin routes to exact Ford room', async () => {
        await visit(beta, '/t/ford');
        await expect(fordLink(beta)).toBeVisible();
        await expect(fordLink(beta)).toHaveAttribute('href', '/messages/live/ford-live');
        await noOverflow(beta);
        await beta.screenshot({ path: resolve(evidence, 'desktop-beta-ford-board.png'), fullPage: true });
        await fordLink(beta).click();
        await expect(beta).toHaveURL(base + '/messages/live/ford-live');
        await expect(beta.locator('.ChatInput textarea')).toBeVisible();
        await expect(beta.locator('.MessagesConversationHeader-title')).toHaveText('Ford Live');
    });
    await record('admin preview is independent of beta membership', async () => {
        await visit(admin, '/t/ford');
        await expect(fordLink(admin)).toBeVisible();
        await fordLink(admin).click();
        await expect(admin.locator('.ChatInput textarea')).toBeVisible();
    });
    await record('ordinary member guest and non-admin moderator have no beta board pin', async () => {
        for (const p of [ordinary, mod, guest]) {
            await visit(p, '/t/ford');
            await expect(fordLink(p)).toHaveCount(0);
        }
    });
    await record('ordinary member and guest exact room UI stays unavailable', async () => {
        for (const p of [ordinary, guest]) {
            await visit(p, '/messages/live/ford-live');
            if (p === guest) await expect(p.locator('.MessagesPage-status')).toContainText('Sign in to view Messages.');
            else await expect(p.locator('.MessagesLiveSurface--unavailable')).toBeVisible();
            await expect(p.locator('.ChatInput textarea')).toHaveCount(0);
        }
    });
    await record('beta send persists and other authorized browser receives by real WebSocket once', async () => {
        let publications = 0;
        admin.on('websocket', ws => ws.on('framereceived', () => publications++));
        await admin.reload({ waitUntil: 'networkidle' });
        await expect(admin.locator('.ChatInput textarea')).toBeVisible();
        const text = 'LOCAL_BETA_FORDF2_' + Date.now();
        const input = beta.locator('.ChatInput textarea');
        await input.fill(text);
        await input.press('Enter');
        await expect(beta.getByText(text, { exact: true })).toHaveCount(1);
        await expect(admin.getByText(text, { exact: true })).toHaveCount(1, { timeout: 15000 });
        assert.ok(publications > 0, 'Actual websocket frames must deliver');
        await admin.reload({ waitUntil: 'networkidle' });
        await expect(admin.getByText(text, { exact: true })).toHaveCount(1);
        await beta.screenshot({ path: resolve(evidence, 'desktop-beta-ford-chat.png'), fullPage: true });
    });
    await record('mobile portrait and landscape keep beta room and composer inside viewport', async () => {
        const mobile = await actor('member', 392, 800);
        await visit(mobile, '/t/ford');
        await expect(fordLink(mobile)).toBeVisible();
        await fordLink(mobile).click();
        await expect(mobile.locator('.ChatInput textarea')).toBeVisible();
        await noOverflow(mobile);
        const composer = await mobile.locator('.ChatInput textarea').evaluate(e => {const r=e.getBoundingClientRect();return {width:r.width,height:r.height,viewport:innerWidth};});
        assert.ok(composer.height >= 44 && composer.width > composer.viewport * .65, 'Mobile composer must fill the available row with a 44px touch target');
        const bubble = await mobile.locator('.ChatMessageGroup--own .message').last().evaluate(e => ({radius:parseFloat(getComputedStyle(e).borderRadius),padding:parseFloat(getComputedStyle(e).paddingTop)}));
        assert.ok(bubble.radius >= 8 && bubble.padding >= 6, 'Messages must retain rounded padded bubbles');
        await mobile.screenshot({ path: resolve(evidence, 'mobile-beta-ford-chat.png'), fullPage: true });
        await mobile.setViewportSize({ width: 801, height: 392 });
        await noOverflow(mobile);
        await expect(mobile.locator('.ChatInput textarea')).toBeVisible();
        await mobile.screenshot({ path: resolve(evidence, 'mobile-landscape-beta-ford-chat.png'), fullPage: true });
    });
    await record('legacy brand slugs target canonical room identities', async () => {
        for (const [slug, key, label] of [['alpha-romeo', 'alfa-romeo', 'Alfa Romeo'], ['genisis', 'genesis', 'Genesis'], ['jlr', 'jlr', 'JLR'], ['range-rover', 'range-rover', 'Range Rover']]) {
            await visit(beta, '/t/' + slug);
            await expect(beta.getByRole('link', { name: 'Open ' + label + ' Public Live chat', exact: true })).toHaveAttribute('href', '/messages/live/' + key + '-live');
        }
    });
    await record('no browser JavaScript exceptions', async () => assert.deepEqual(errors, []));
    console.log('F2_BRAND_LIVE_COMPILED_BROWSER=PASS scenarios=' + results.length);
} finally {
    for (const c of contexts) await c.close();
    await browser.close();
    writeFileSync(resolve(evidence, 'brand-live-browser-results.json'), JSON.stringify({ results, errors }, null, 2));
}
