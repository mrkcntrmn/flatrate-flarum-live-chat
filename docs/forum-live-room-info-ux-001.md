# FORUM-LIVE-ROOM-INFO-UX-001 — Remove read-only participant chips

**Date:** 2026-10-19
**Work order:** FORUM-LIVE-UI-CLEANUP-001-R2
**Status:** source implemented and locally built. Draft PR only. Not merged, not deployed, not production-verified.

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

GitHub check run IDs are recorded after the exact-head CI result. A green check is not a deployment.

## Lifecycle

| Stage | State |
| --- | --- |
| Source implemented | yes, on draft PR #52 |
| Built | yes, local Node 20 production build |
| CI qualified | pending the exact-head run for this revision |
| Draft PR ready | after that run is green |
| Merged | no |
| Deployed | no |
| Production verified | no |

**Paired candidate:** `flatrate-flarum-messaging-ui` PR #25 removes the redundant All messages overflow action.
