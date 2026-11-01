# FORUM-GENERAL-LIVE-20261101 — Flarum 2 MAIN provider restoration

Document class: SOURCE_CANDIDATE / RELEASE_PREPARATION  
Status: SOURCE EDITS PREPARED; CI/BUILT ARTIFACT/HOSTED ACCEPTANCE NOT YET PROVEN

## Contract

```text
WORK_ORDER=FORUM-GENERAL-LIVE-20261101-G0
OUTCOME=Prepare the installed Flarum 2 Live Chat source for all eligible signed-in members to reach General Live from MAIN
BOUNDARIES=Source, regression tests and documentation only; no production package upgrade, room mutation, flag change, tester grant, Brand visibility or moderator invitation
SOURCE_OF_TRUTH=Live installed #57 @ 4c73c626a22e60eb6c31a5ae4f7c5962642d14eb and wiki F2 production checkpoint 2026-10-30
DONE=Provider registered in F2, actor-gated, built, exact-head CI green and disposable real-browser/real-Centrifugo smoke passed; then source is release-review ready
CONSEQUENTIAL_BOUNDARY=Do not publish/install/enable General without separate owner-approved provider-side release receipt
```

## Verified source omission

The installed Flarum 2 `js/src/forum/index-f2.js` registers `registerLiveMessagingProvider()` and `registerLiveBoardProvider()`, but **does not register `registerLiveMainProvider()` or call `startLiveMainProvider()`**. F1 `index.js` does both. F2 ForumResourceFields already serializes actor-effective `flatrate-live-chat.main_live_available`, and the exact installed Forum Navigation MAIN pin fails closed when `app.flatRateLiveMain` is missing. Consequently, toggling General's two existing settings by itself cannot be counted as a complete public MAIN launch.

The bounded fix wires the existing provider and lifecycle into F2 without changing permissions, the Centrifugo backend or existing Brand/Direct code. The provider itself checks authenticated session plus the actor-effective server attribute; it never independently authorizes API history, posting or realtime.

### Required build boundary

The source repository tracks compiled `js/dist/forum-f2.js` (and its map). **Source-only JS changes are not a served package.** In an isolated checkout, run the repository's actual F2 build and applicable tests, inspect the resulting bundle and commit only the qualified tracked build artifacts. Do not install a package that points at a new PHP/JS source SHA but serves old F2 JavaScript.

The structural regression `js/tests/f2-live-provider-entry.test.mjs` previously asserted that MAIN provider registration was absent. Update it to assert presence plus actor-derived eligibility. Do not count a text assertion alone as browser acceptance.

## Acceptance before package promotion

- Test a signed-in **ordinary non-beta** member with General master and User Live enabled. General MAIN row must appear once on clean MAIN page 1 and take the actor to `/messages/live/community-general-live`; direct refresh/hard-load also works.
- Beta Tester projection can be disabled/absent for this actor. General must still allow authorized room detail, history, post and token issuance. No beta eligibility or Brand subscription is required.
- Signed-out guest sees no unauthorized MAIN Live row; direct General room detail/history/post/subscribe/realtime are denied. Any guest call-to-action is separately owned by the landing/navigation product.
- Suspended user and member without required chat permission cannot post or subscribe. Explicit non-chat actors may not see the MAIN row.
- Master `general_live_enabled=0` denies General direct history/post/token even when `general_live_user_enabled=1`. Unknown/malformed setting values must not enable the General operational master.
- Existing 45 Brand rooms retain `hidden/staff-preview`; ordinary non-beta members see none, cannot enumerate via directory, fetch history, post or subscribe, and are denied exact noncanonical room keys.
- Existing approved Brand beta/staff behavior and private Direct messages remain unchanged.
- Moderator/admin can delete abusive messages; a nonmoderator cannot delete others' content; rate limits enforced; existing General OFF path can contain new access quickly. Previously issued realtime subscriptions can live up to 300 seconds; this is an accepted bound, not instantaneous revocation.
- Desktop and physical Pixel 5: keyboard/portrait/landscape, one MAIN row, canonical chat title, send/echo, deletion clearing open view without reload, back navigation and no console faults.
- Full F2 PHP suite, JS suite, actual compiled bundle test, real Centrifugo end-to-end and exact-head GitHub CI pass.

## Production staging and gate order (NOT an instruction to execute now)

Preflight must read the **current** exact installed source, complete durable `/data/extensions/list`, SHA-256, current room record, existing settings, provider/asset health, and restore manifest; compare to authorized target. Avoid changing any Brand row, permission, beta cohort, user or global navigation.

Safe sequencing:
1. Hold General operational master **OFF** (explicit `0`) while qualifying and installing the exact built source, if an install is approved.
2. With master still OFF, enable the independently actor-gated `general_live_user_enabled=1` surface. Verify the MAIN row remains suppressed and General APIs deny.
3. Verify General canonical row is `room_key=community-general-live`, `visibility=visible`, `audience=members`, type 1, and current sitewide chat read/post permissions for ordinary members. Never mass-edit rooms.
4. In the separately authorized rollout, enable the General master `general_live_enabled=1` **last**, then perform consented normal-member hosted acceptance, without synthetic production accounts.
5. Keep `live_chats_navigation_enabled=0` and Brand/member rollout defaults unchanged; the actor-specific MAIN row does not require the broad legacy directory toggle.
6. Monitor real delivery, moderation activity, error rate, provider health, and aggregate count-only presence, without accessing private messages or exposing identities. Historical uptime/latency instrumentation is not installed.

First emergency containment: set `general_live_enabled=0` (server-side), then verify denied API/token issuance and hidden MAIN row; allow for prior issued subscription expiry up to 300 seconds. If package code rollback is required, preserve the complete predeployment manifest and perform only the explicitly approved same-image rollback. Do **not** restore F1 pins, database, or unrelated packages as an automatic fallback.

## Separate follow-up, not this work order

- Brand-room invitation URLs shared inside General; no beta status or moderator role granted by Brand participation links.
- Private scoped moderator appointments and approval.
- Guest public-read mode, optional; *not* selected.
- General message analytics beyond existing aggregate-only admin instrumentation; no message body analytics.

## Coordination

```text
COORDINATION_KEY=FORUM-GENERAL-LIVE-20261101-G0
WRITE_SCOPE=js/src/forum/index-f2.js;js/tests/f2-live-provider-entry.test.mjs;docs/f2-general-public-main-provider-20261101.md
OPEN_PR_OVERLAP=#55/#56/#57 F2 stack (this is based exactly on accepted deployed #57); #49 general-beta-only policy is superseded; #50 all-Brand policy is out of scope
AUTHORITY_REVIEWED=F2 production current status and inventory, accepted #57, available source and release controls
DOCS_IMPACT=bounded source/readiness note; no current production authority altered
PROJECT_ROUTING_IMPACT=not_applicable
HANDOFF_STATUS=SOURCE_CANDIDATE / BUILD_AND_DISPOSABLE_ACCEPTANCE_REQUIRED
```
