# Flarum 2 Live monitoring admin UI

Work order: `FORUM-F2-ADMIN-MONITORING-20261030`.
Base: Live source `c38c3b6eebf661c99d67d575f1a822abc1dc7cff`.

The F2 extension entry registered the forum frontend but omitted admin JavaScript and CSS. The legacy admin initializer also used F1's `extensionData` rather than F2's `app.registry`. The read-only dashboard API and metric calculation already existed; this change restores their admin presentation without adding data collection or changing authorization.

## Implementation

- Register `admin-f2.js` and the established admin stylesheet in `extend-f2.php`.
- Compile a dedicated F2 admin entry with admin/common registry IDs, separate from the existing forum compiler. Keep the tracked F1 admin entry and share its eleven existing settings and permission registrations through a registry adapter. Existing defaults, including dashboard default OFF, are preserved.
- Use the F2 extension page and canonical `/messages/live/{roomKey}` link. The F1 page retains its existing room route.
- Ignore superseded responses and responses arriving after removal; cancel the preceding refresh timer when a new request starts. Success refreshes after 60 seconds, failure retains the timestamped previous snapshot and retries after 120 seconds. Removed pages do not resume polling.
- Label room search and sort controls, provide 44px controls on mobile/touch devices, and keep the room Inspect action visible while telemetry columns scroll.

No migrations, external dependency versions, backend metrics, authorization rules, users, roles, room catalog, cohort or Live rollout controls change. The forum JavaScript and source maps remain byte-for-byte identical to the base.

## Verification

The exact deployed 205-package composition was reproduced in a synthetic loopback fixture with PHP 8.4.27, Flarum 2.0.2, MariaDB 11.4 and real Centrifugo 6.9.4. Fixtures contain three visible normal messages, one system event and a deleted normal message. The dashboard must report the normal/system totals separately without exposing the synthetic private-body sentinel.

- PHP suite: 234 tests / 3,894 assertions, PASS.
- JavaScript suite and production build, PASS. The compiled-bundle test executes the F2 initializer against the admin registry and checks gate-on/gate-off registration. Lifecycle tests cover late previous-room responses, unmount with pending success/failure, timer cancellation and recovery.
- Ten compiled full-application browser scenarios, PASS: actual admin page; real aggregate/privacy projection; guest/member/beta/non-admin-moderator denial; room search/sort/no-results; selected-room true zero; a real authorized WebSocket changing presence to one; provider failure reported unavailable; refresh/backoff/recovery; unmount; mobile geometry and no browser exceptions. Dashboard requests are GET-only.
- Four physical Pixel 5 scenarios, PASS: authenticated synthetic overview; touch filtering and visible 44px Inspect; selected-room refresh and canonical link; landscape/no overflow. Original cookies, tabs and rotation are preserved and owned ADB forwards removed.

The reusable browser harness is `js/tests/f2-admin-monitoring-browser.mjs`. Set `F2_AUTH_FILE` to the existing synthetic member-experience auth format (`baseUrl`, `users.admin/member/peer/outsider.token`), `PLAYWRIGHT_PACKAGE_JSON`, `F2_EVIDENCE_DIR`, and `F2_PRESENCE_FAILURE_FLAG`. It rejects non-loopback fixtures. The failure flag belongs to the owned `/tmp/fr-f2-admin-monitoring-*/private/` fixture and causes its protocol-equivalent local presence edge to return 503; it is removed after the test. Production credentials and data are never used in this harness.

## Acceptance boundary

Historical realtime uptime and latency remain explicitly **not instrumented**. The dashboard reports catalog integrity, rolling activity aggregates, existing rollout flags and on-demand count-only presence for one selected canonical room. Unknown provider counts remain unavailable, not zero. No participant identities or message bodies are requested by the dashboard.

These results are local source qualification. Bind CI to the exact candidate commit before any approved package promotion. Hosted acceptance must verify the admin page, overview, selected-room detail, search/sort and refresh under an already-authorized real administrator. Preserve current rollout/security controls. No source merge, production deployment or new monitoring setting change is authorized by this document.
