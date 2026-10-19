# FORUM-LIVE-ROOM-INFO-UX-001 — Remove read-only participant chips

**Date:** 2026-10-19
**Work order:** FORUM-LIVE-UI-CLEANUP-001-R2
**Status:** source implemented, built, and CI-qualified on draft PR #52. Not merged, not deployed, not production-verified.

**Qualified source SHA:** `092b2494d312fc3271bd215deb0ab96416762ca0` (JavaScript and bundle). This documentation note does not change those assets.
**CI:** pull request run [39308825430](https://github.com/mrkcntrmn/flatrate-flarum-live-chat/actions/runs/39308825430) success (`flarum-resolve`, `test`). Push run [39308817160](https://github.com/mrkcntrmn/flatrate-flarum-live-chat/actions/runs/39308817160) success on the same SHA.

## Work contract

- **WORK_ORDER:** FORUM-LIVE-ROOM-INFO-UX-001
- **OUTCOME:** General Live and canonical Brand Live Room info show the room title and the existing leave/rejoin action, without a list of room participant usernames.
- **BOUNDARIES:** presentation only. No changes to membership, role assignments, chat messages, moderation authorization, message authors, realtime, presence totals, subscriptions, or server/API serialization.
- **SOURCE_OF_TRUTH:** `js/src/forum/utils/liveChatPresentation.js` (`readOnlyRoomInfoPresentation`, `roomInfoSurface`) and `js/src/forum/components/ChatEditModal.js`.
- **DONE:** ordinary room info for General and Brand hides participant chips; Direct and group info still show users; authorized moderator room-management remains available; JavaScript tests pass; tracked `js/dist` matches a Node 20 production build.
- **CONSEQUENTIAL_BOUNDARY:** package release, installation, and production deployment require separate owner authorization.

## Implementation

Canonical keyed Live rooms are `type=1` channels (`src/Provisioner/RoomReconcileSnapshot.php`). `readOnlyRoomInfoPresentation()` keeps the room title and calls the participant-chip renderer only when `type` is falsy. A hidden list is `null`, so Room info does not leave an empty `UsersTags` container.

`roomInfoSurface()` selects the existing modal path:

| Model | Actor | Surface |
| --- | --- | --- |
| type 1 channel (General or Brand Live) | member | `channel-readonly` |
| type 1 channel | authorized moderator | `channel-moderator` |
| type 0 Direct (`isChatPM`) | any, including a moderator | `direct-readonly` |
| type 0 group | member | `group-readonly` |
| type 0 group | authorized moderator | `group-moderator` |

`componentFormChannel()` still renders the moderator user-management form. `componentFormButtons()` still renders Leave / Return and the existing save path. `onleave()` is unchanged.

This does not anonymize message authors, delete members, hide the aggregate LIVE count, or grant moderator actions to ordinary members.

## Local qualification

- Node `v20.19.5` (CI uses Node 20).
- `cd js && npm ci && npm test && npm run build` passed, including `LIVE_CHAT_UI_CONTRACT=PASS`.
- Behavioral checks cover General Live, Toyota Live, Direct, group, moderator, and non-moderator surfaces. The chip renderer is not called for type 1 rooms.
- Tracked assets changed: `js/dist/forum.js`, `js/dist/forum.js.map`. `admin.js` was not modified.
- A second `npm run build` produced byte-identical `js/dist` output.
- Bundle contains `channel-readonly`, `channel-moderator`, `direct-readonly`, `group-moderator`, `UsersTags`, and `ButtonLeave`.

A green check is not a deployment. The disposable Flarum 1.8.19 forum compiled these assets into `public/assets/forum.js` (`channel-readonly` present). Signed-in Room info was not walked because that disposable forum has no provisioned Live rooms. Physical Android acceptance was not run.

## Lifecycle

| Stage | State |
| --- | --- |
| Source implemented | yes, on draft PR #52 |
| Built | yes, local Node 20 production build |
| CI qualified | yes, runs 39308825430 and 39308817160 at `092b2494` |
| Draft PR ready | yes, still draft |
| Merged | no |
| Deployed | no |
| Production verified | no |

**Paired candidate:** `flatrate-flarum-messaging-ui` PR #25 removes the redundant All messages overflow action.

## R3 signed-in acceptance

**Work order:** FORUM-LIVE-UI-CLEANUP-001-R3
**Date:** 2026-10-19
**Product source:** unchanged at `87957935b59e0fbc84855abc48a1f6d380029e6d`
**Bundle:** `js/dist/forum.js` sha256 `7d43baacaf1c0cb43f6fbceb07548fb980617fe88c046f11f2540cff62b252f5`
**CI still valid for that product head:** run [39310133089](https://github.com/mrkcntrmn/flatrate-flarum-live-chat/actions/runs/39310133089) success. This note does not change JavaScript or `js/dist`.

The R2 statement that signed-in Room info was not walked is historical. R3 reused the isolated Flarum 1.8.19 disposable forum at `http://127.0.0.1:8137` (`DATABASE_ENVIRONMENT=DISPOSABLE`, database host `fr-live-ui-cleanup-db`). Canonical rooms were created with `RoomProvisioner` reconcile: 46 keyed rooms, identities unchanged. General Live and Toyota Live each have three disposable participants at pivot role 0 (`r3member`, `r3peer`, `r3mod`). Inspection did not change that roster. One pre-existing unkeyed chat remains and was not part of the catalog.

| Check | Result |
| --- | --- |
| General Live, member `r3member` | Room info title General Live. Loaded usernames `r3member`, `r3peer`, `r3mod`. Rendered participant chips: 0. Leave the chat present and not used. |
| Toyota Live, moderator `r3mod` (pivot role 0, staff preview) | Header title Toyota Live. Overflow label Room info. Modal text is the title, Leave the chat, and the pre-existing Delete control. Usernames are absent from the modal and the accessibility tree. |
| Toyota Live, administrator | Overflow label Room settings. Management form lists the three disposable users. Save, Enter, and Delete were not used. |
| Ordinary member on `/messages/live/toyota-live` | Denied: "This live chat isn't available." Hidden brand rooms were not listed in that member's directory. |
| Back from Toyota Live | `/t/toyota` |
| Membership during inspection | unchanged, role 0, three participants |

`r3mod` sees Delete chat because the existing `create.channel` permission is true for that actor. That control is not a participant directory and was not introduced by this change. The ordinary member Room info does not show it.

Desktop and emulated CSS viewports 360, 390, and 412 had no horizontal overflow. Header side slots measured 44 by 44. Room info focus landed on Close. Physical Samsung SM-S916U / Android 16 was not connected. A Pixel 5 on Android 14 was attached and was not used as a substitute. `ANDROID_PHYSICAL_ACCEPTANCE=NOT_PROVEN`.

Local evidence, not committed: `/home/ilove/dev/_disposable/forum-live-ui-cleanup-001-r3/evidence/`.

Messaging UI PR #22 remains independent at `7a31f0e917c1d3939d0ff606883ab094be444e3b`. This acceptance did not merge, rebase, or deploy.
