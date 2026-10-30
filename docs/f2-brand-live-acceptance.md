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
