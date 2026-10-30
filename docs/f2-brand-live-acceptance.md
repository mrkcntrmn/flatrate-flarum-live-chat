# Flarum 2 Brand Live restoration

Work order: `FORUM-F2-BRAND-LIVE-ACCEPTANCE-20261029`.
Base: installed Live source `b048f30a6bdef3e8bb1d29ded9ebd1975688603c` (PR #55).

The F2 browser entry registered the Messages provider but omitted the Brand provider consumed by Forum Navigation. As a result, enabled Brand Live had no board entry. The F2 stylesheet also excluded the existing composer and message layout, and room documents omitted the display metadata used by the conversation header.

This successor registers the existing actor-gated Brand provider, reuses the established conversation styles inside the Messages surface with locally scoped compatibility names, and serializes display metadata only after room authorization. Room participant identities remain absent. General MAIN provider behavior, room visibility, beta membership, permissions, token lifetime, and the catalog are unchanged.

## Reusable application acceptance

`js/tests/f2-brand-live-browser.mjs` runs against a synthetic loopback Flarum application, rather than mocked provider objects. Supply `F2_AUTH_FILE`, `F2_EVIDENCE_DIR`, and `PLAYWRIGHT_PACKAGE_JSON`. The fixture uses the existing member-experience auth format (`baseUrl`, `users.admin/member/peer/outsider.token`) with admin, active beta member, ordinary member and non-admin moderator. Seed the canonical catalog, enable the beta and admin-preview gates, keep General Live/User Live off, and run a disposable real Centrifugo server.

The test covers the compiled board entry and exact-room transition; independent admin preview; absent ordinary-member/guest/moderator beta pins; explicit unauthorized room UI; real websocket delivery and reload persistence; full-width 44px mobile composer and padded rounded bubbles; canonical header title; legacy Alfa Romeo/Genesis and family-board routing; and browser exceptions.

Local qualification used the exact 205-package deployed dependency matrix, PHP 8.4.27, Flarum 2.0.2, isolated MariaDB, generated test-only RSA keys, and disposable Centrifugo 6.9.4:

- Existing PHP suite: 233 tests, 3,862 assertions, pass.
- All-45 Brand API access matrix plus history, General-off and JWT binding/privacy: 195 checks, pass.
- Compiled desktop/mobile browser: eight scenarios, pass.
- Physical Pixel 5: four scenarios, pass, including touch entry, keyboard focus and synthetic send, portrait/landscape, and Ford-to-JLR isolation. Original cookies/tabs and rotation were preserved; temporary forwards were removed.
- JavaScript suite and tracked bundle build: pass.

## Hosted rollout boundary

The requested audience is the existing approved beta cohort only. Do not enable broad User Live, change room visibility/audience, add testers, create accounts, or enable General Live as a side effect. Stage explicit controls before enabling the extension so historical General defaults cannot expose it during activation.

Actual hosted publish-edge acceptance remains required. This source change does not rotate or export production secrets. Already-issued subscriptions may remain valid for up to the existing 300-second expiry after access revocation; new credentials are denied once access is revoked. An explicit owner disposition remains required for that known bound before restricted-room activation. Local publisher tests do not prove the production edge.

No source merge, production package promotion, feature activation, or production message write is claimed by these local results. Exact successor CI and a bounded hosted acceptance action precede that boundary.

## Hosted acceptance correction, 30 October 2026

The owner approved `68e1382` for pod 148142 and the existing beta cohort, including one labelled native-admin Ford message and its deletion. The installed source and durable pin were verified. Production WebSocket observations received both `message.created` and `message.deleted`; the selected real beta browser displayed the created message once without reload. The test message was deleted and was absent from server history.

Hosted UI acceptance exposed two defects: the already-open receiver retained the deleted row, and its author's avatar linked to `/u/undefined`. Both are `BLOCKS_DONE`. Beta and admin-preview gates were returned to OFF; General/User Live and broad navigation remain OFF. No additional production message has been sent. A fresh synthetic account also reproduced a raw room-key header when it had no directory row for that room.

The correction confirms deletion through authorized, room-scoped HTTP history independently of the viewport fetch lock. It retries a bounded persistence race, removes only the confirmed room/message pair, and prevents a late history result from restoring that row. Failed requests and still-present rows remain untouched. F2 message authors receive the canonical slug from Flarum's configured `SlugManager`, without adding private user fields. The provider supplies the shell's conversation context only from the current session's already-authorized exact-room snapshot, so a first visit receives its canonical title without enumerating rooms or creating membership.

The regression now checks a fresh member's board-to-room title before any send, the canonical author link, and deletion clearing both open browsers without refresh. Focused deletion tests cover persistence-before-notification races, failed HTTP, retained rows, unrelated rooms/messages, and late-fetch resurrection. These changes require an amended exact-source deployment approval; the original `68e1382` approval is not reused for a different commit or a second production test message.

The accepted revocation rule is operational: turn beta Live OFF before cohort changes, confirm the signed forum projection synchronization, and allow up to 300 seconds for previously issued subscriptions to expire before reopening. This does not claim instant Supabase-only revocation or immediate invalidation of an issued subscription.

Correction qualification: 234 PHP tests / 3,876 assertions; JavaScript suite and production build; nine compiled browser scenarios with real Centrifugo, canonical author href and deletion clearing both browsers without reload; five physical Pixel 5 scenarios, including no-refresh deletion. All pass. External package versions remain the same deployed 205-package composition. The previous 195-check authorization/catalog matrix is reused because this correction changes no gate, permission, room catalog, membership or token issuance logic; the new compiled run independently rechecks ordinary-member/guest denial. Hosted acceptance of this correction is still pending.
