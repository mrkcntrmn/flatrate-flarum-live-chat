# FORUM-LIVE-ROOM-INFO-UX-001 — Remove read-only participant chips

**Date:** 2026-10-19  
**Status:** source-only draft candidate; no deployment or production changes.

## Work contract

- **WORK_ORDER:** FORUM-LIVE-ROOM-INFO-UX-001
- **OUTCOME:** General Live and canonical Brand Live Room info show the room title and existing leave/rejoin action, without a list of room participant usernames.
- **BOUNDARIES:** presentation only. No changes to membership, role assignments, chat messages, moderation, message authors, realtime, presence totals, subscriptions, or server/API serialization.
- **SOURCE_OF_TRUTH:** `js/src/forum/components/ChatEditModal.js` and current source tests.
- **DONE:** ordinary room info for both General and Brand hides participant chips; Direct/group chat info continues to show expected users; authorized moderator room-management form remains available; tests pass, production bundle rebuilt and qualified.
- **CONSEQUENTIAL_BOUNDARY:** package release / installation / production deployment requires separate owner authorization.

## Implementation

`componentChatInfo()` keeps `this.model.title()`, and renders `componentUsersMentions()` only when `this.model.type()` is false (Direct/group). Public Live rooms are channels (`type()` true). `componentFormChannel()` continues to expose the separate user-editing controls to an authorized room moderator; backend membership is untouched.

This does **not** anonymize senders within messages, delete members, hide the existing aggregate LIVE count, or grant moderator actions to ordinary members.

## Qualifications before promotion

1. Run `cd js && npm test && npm run build`; reconcile any tracked `js/dist` requirements on the exact branch.
2. Test Room info for General Live and at least one Brand Live with an authorized ordinary/beta session; title and leave remain, participant chips absent.
3. Confirm the existing room-management form for an authorized moderator, and ensure Direct/group participant display remains intact.
4. Check phone-width layout on Android; no horizontally clipped controls.
5. Only then request permission for release/deployment; source merge is not deployment.

**Parallel messaging UI work:** remove the redundant `All messages` overflow action in `flatrate-flarum-messaging-ui` as a separate source candidate.
