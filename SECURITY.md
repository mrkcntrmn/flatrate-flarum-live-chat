# Security — FORUM-CHAT-001C

FlatRate hardening status including rollout visibility and realtime transport AuthZ.

```text
CHAT_IP_PERSISTENCE=false
CHAT_EMAIL_NOTIFICATIONS=false
CHAT_ATTACHMENTS_INITIAL=false
CHAT_INDEXING=false
SHARED_PUBLIC_PUSHER_CHANNEL=false
DM_COUPLING=false
TRANSPORT_IMPLEMENTATION=PUSHER_CHANNELS
transportDecision=PUSHER_CHANNELS
transportImplementationStatus=implemented/complete
transportExternalQualification=PENDING
productionPusherConfigured=false
PUSHER_SELECTED=false
PUSHER_AUTHORIZED=false
PUSHER_CONFIGURED=false
ROLLOUT_PROFILE=general-live-first
DISPOSABLE_RUNTIME_PASS=true
```

## MUST_FIX classification (CHAT-001B carry-forward)

| ID | Status |
| --- | --- |
| SERVER_SIDE_ENABLED_PERMISSION | FIXED_AND_TESTED |
| PUBLIC_ROOM_AUTHZ | FIXED_AND_TESTED |
| PER_ROOM_REALTIME_ISOLATION | FIXED_AND_TESTED |
| TRANSPORT_SUBSCRIPTION_AUTH | FIXED_AND_TESTED (auth endpoint + ACL; external Pusher qual PENDING) |
| FLOODGATE | FIXED_AND_TESTED |
| GUEST_ENUMERATION | FIXED_AND_TESTED |
| USER_CREATED_CHANNEL_DISABLE | FIXED_AND_TESTED |
| PRIVATE_PM_GROUP_DISABLE | FIXED_AND_TESTED |
| SCRIPT_REEXECUTION_REMOVAL | FIXED_AND_TESTED |
| SERIALIZER_ALLOWLISTS | FIXED_AND_TESTED |
| CANONICAL_ROOM_IDENTITY | FIXED_AND_TESTED |
| MODERATION_ENFORCEMENT | FIXED_AND_TESTED |
| CHAT_ATTACHMENTS_DISABLED | FIXED_AND_TESTED |
| CHAT_INDEXING_DISABLED | FIXED_AND_TESTED |
| PUBLIC_CHANNEL_AUTOJOIN_ON_READ | FIXED_AND_TESTED |
| CHAT_IP_PERSISTENCE | FIXED_AND_TESTED |
| CHAT_EMAIL_NOTIFICATIONS | FIXED_AND_TESTED |
| HIDDEN_ROOM_SERVER_SIDE | FIXED_AND_TESTED (unit; HTTP matrix in disposable) |

```text
MUST_FIX_REMAINING_COUNT=0
```

## Rollout / realtime security notes

- Hidden brand rooms are omitted from member list queries (404 on direct access).
- Realtime auth signs only `private-flatrate-live-*` channels the actor may read.
- Fail closed without complete Pusher credentials: no publish, no client connect.
- Structured logs for publish/auth allow/deny never include message bodies or secrets.
- Production Pusher credentials and Packagist publish are **not** authorized by CHAT-001C.
