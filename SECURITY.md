# Security — FORUM-CHAT-001B

FlatRate hardening status for MUST_FIX items from
[`docs/forum-chat-001a-neon-audit.md`](https://github.com/mrkcntrmn/flatrate-wiki/blob/main/docs/forum-chat-001a-neon-audit.md).

```text
CHAT_IP_PERSISTENCE=false
CHAT_EMAIL_NOTIFICATIONS=false
CHAT_ATTACHMENTS_INITIAL=false
CHAT_INDEXING=false
SHARED_PUBLIC_PUSHER_CHANNEL=false
DM_COUPLING=false
REALTIME_IMPLEMENTATION_DECISION=PENDING
TRANSPORT_SUBSCRIPTION_AUTH=PENDING
PUSHER_SELECTED=false
PUSHER_AUTHORIZED=false
PUSHER_CONFIGURED=false
DISPOSABLE_RUNTIME_PASS=true
```

## MUST_FIX classification

| ID | Status | UNIT | HTTP_RUNTIME |
| --- | --- | --- | --- |
| SERVER_SIDE_ENABLED_PERMISSION | FIXED_AND_TESTED | PASS (`ChatAuthorizationTest`) | PASS (booted API matrix) |
| PUBLIC_ROOM_AUTHZ | FIXED_AND_TESTED | PASS | PASS (guest/member/type=0) |
| PER_ROOM_REALTIME_ISOLATION | FIXED_AND_TESTED | PASS (`FakeRealtimePublisher`) | PASS publish-scope via HTTP post path; `TRANSPORT_SUBSCRIPTION_AUTH=PENDING` |
| FLOODGATE | FIXED_AND_TESTED | PASS binding | PASS session-token HTTP 429 (ApiKey bypass is core Flarum) |
| GUEST_ENUMERATION | FIXED_AND_TESTED | PASS | PASS (guest mutating API rejected; history AuthZ) |
| USER_CREATED_CHANNEL_DISABLE | FIXED_AND_TESTED | PASS | PASS member create/rename/delete 403 |
| PRIVATE_PM_GROUP_DISABLE | FIXED_AND_TESTED | PASS | PASS type=0 HTTP 403; `DM_COUPLING=false` |
| SCRIPT_REEXECUTION_REMOVAL | FIXED_AND_TESTED | STATIC_GUARD=PASS | BROWSER_RUNTIME=CLI_CURL (no `createElement('script')`; serializer safe) |
| SERIALIZER_ALLOWLISTS | FIXED_AND_TESTED | PASS | PASS (no `ip_address` in HTTP JSON) |
| CANONICAL_ROOM_IDENTITY | FIXED_AND_TESTED | PASS | PASS schema UNIQUE + reconcile 42 |
| MODERATION_ENFORCEMENT | FIXED_AND_TESTED | PASS | PASS suspended HTTP 403 |
| CHAT_ATTACHMENTS_DISABLED | FIXED_AND_TESTED | PASS | PASS forum attribute `attachments=false` |
| CHAT_INDEXING_DISABLED | FIXED_AND_TESTED | PASS | PASS `/chat` `noindex` |
| PUBLIC_CHANNEL_AUTOJOIN_ON_READ | FIXED_AND_TESTED | PASS | PASS (membership only on post) |
| CHAT_IP_PERSISTENCE | FIXED_AND_TESTED | PASS | PASS `IP_DATABASE_WRITE_COUNT=0` |
| CHAT_EMAIL_NOTIFICATIONS | FIXED_AND_TESTED | PASS | PASS no chat notification rows / attribute false |

```text
MUST_FIX_REMAINING_COUNT=0
```

## Remaining risks (non-blocking for B / R1)

- Real transport (Pusher vs websocket) not selected — PENDING by design (`CHAT-001C`).
- Transport subscription AuthZ deferred until transport selection.
- Frontend UX polish deferred to CHAT-001C.
