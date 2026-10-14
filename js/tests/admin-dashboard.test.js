#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const page = fs.readFileSync(path.join(root, 'js/src/admin/components/LiveChatRoomsDashboard.js'), 'utf8');
const index = fs.readFileSync(path.join(root, 'js/src/admin/index.js'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'src/Api/Controllers/AdminDashboardController.php'), 'utf8');
const service = fs.readFileSync(path.join(root, 'src/Admin/AdminDashboardService.php'), 'utf8');

assert.match(index, /flatrate-live-chat\.admin_dashboard_enabled/);
assert.match(index, /default:\s*false/);
assert.match(index, /registerPage\(LiveChatAdminPage\)/);

assert.match(page, /REFRESH_MS = 60000/);
assert.match(page, /RETRY_MS = 120000/);
assert.match(page, /encodeURIComponent\(roomKey\)/);
assert.match(page, /Presence is sampled only for the room you open/);
assert.match(page, /Catalog reconciled/);
assert.match(page, /Catalog needs attention/);
assert.match(page, /Catalog integrity only/);
assert.match(page, /Historical realtime health is not instrumented/);
assert.match(page, /shows Unavailable rather than zero/);
assert.match(page, /FlatRateLiveAdminTableWrap/);
assert.doesNotMatch(page, /Operational/);
assert.match(page, /Message text and participant identities are never shown/);
assert.doesNotMatch(page, /lastMessage|messageBody|messageText|participantList/);

assert.match(controller, /assertAdmin\(\$actor\)/);
assert.match(controller, /X-Robots-Tag/);
assert.match(controller, /Cache-Control/);
assert.match(service, /selected-room-on-demand/);
assert.match(service, /catalog_reconciled/);
assert.match(service, /not_instrumented/);
assert.doesNotMatch(service, /'operational'/);
assert.match(service, /whereNull\('deleted_by'\)/);
assert.match(service, /where\('created_at', '>=', \$bounds\['week'\]\)/);
assert.match(service, /ORDER BY id DESC LIMIT 1/);
assert.match(service, /COUNT\(DISTINCT CASE WHEN type = 0/);
assert.doesNotMatch(service, /MAX\(created_at\)/);
assert.doesNotMatch(service, /->message\b|\['message'\]/);

console.log('FORUM_LIVE_ADMIN_001_UI=PASS');
