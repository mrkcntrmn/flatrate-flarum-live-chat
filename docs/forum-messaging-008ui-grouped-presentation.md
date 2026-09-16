# FORUM-MESSAGING-008UI — Grouped Message Identity + Mirrored Metadata

## Objective

Presentation-only refinement of Messages V2 Live/group chat:

- identity (avatar + nickname) once per consecutive same-author run
- timestamp once per group (first message)
- mirrored own header: `time … Nickname: [avatar]`
- incoming header: `[avatar] Nickname: … time`
- no per-message ellipsis on V2
- system/event messages break groups
- 5-minute gap breaks groups

## Architecture

```text
ChatViewport (presentationVersion=2)
  -> groupChatMessages(ordered models)
      -> ChatMessageGroup (header + bodies)
      -> ChatEventMessage (group breaker)
```

`ChatMessage` with `grouped={true}` renders content only (`v2GroupedContent`).

## Files

| Path | Role |
|------|------|
| `js/src/forum/utils/groupChatMessages.js` | Presentation grouping |
| `js/src/forum/components/ChatMessageGroup.js` | Group shell |
| `js/src/forum/components/ChatViewport.js` | V2 group render + optimistic preview in collection |
| `js/src/forum/components/ChatMessage.js` | Grouped content path; V1 ellipsis preserved |
| `resources/less/forum/ChatViewport.less` | Group header + mirrored own CSS |
| `js/tests/forum-messaging-008ui.test.js` | Grouping + static contracts |

## Non-goals

Auth, Centrifugo, schema, unread, composer, search, notifications unchanged.

## Work order

`FORUM-MESSAGING-008UI-GROUPED-MESSAGE-PRESENTATION-R1`
