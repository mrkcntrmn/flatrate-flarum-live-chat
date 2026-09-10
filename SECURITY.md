# Security — FORUM-CHAT-CENTRIFUGO-002A

FlatRate hardening status including rollout visibility and self-hosted Centrifugo transport AuthZ.

```text
NEXT_VERSION=1.1.1
SOURCE_IMPLEMENTED_TARGET=1.1.1
RC_DISTRIBUTED=false
STABLE_RELEASED=1.1.0
PRODUCTION_DEPLOYED=false
CHAT_IP_PERSISTENCE=false
CHAT_EMAIL_NOTIFICATIONS=false
CHAT_ATTACHMENTS_INITIAL=false
CHAT_INDEXING=false
SHARED_PUBLIC_REALTIME_CHANNEL=false
DM_COUPLING=false
TRANSPORT_IMPLEMENTATION=CENTRIFUGO_SELF_HOSTED
transportDecision=CENTRIFUGO_SELF_HOSTED
transportImplementationStatus=stable-distributed-qualified (1.1.0); 1.1.1 source in progress
productionCentrifugoConfigured=false
PUSHER_SELECTED=false
ROLLOUT_PROFILE=general-live-first
```

## MUST_FIX classification (CHAT-001B/001C carry-forward)

| ID | Status |
| --- | --- |
| SERVER_SIDE_ENABLED_PERMISSION | FIXED_AND_TESTED |
| PUBLIC_ROOM_AUTHZ | FIXED_AND_TESTED |
| PER_ROOM_REALTIME_ISOLATION | FIXED_AND_TESTED |
| TRANSPORT_SUBSCRIPTION_AUTH | FIXED_AND_TESTED (connect/subscription JWT + ACL) |
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

## Secret storage (1.1.1 source)

- Secrets must remain outside the public web root.
- No secrets in `/data/extensions/list`, Flarum settings DB, forum serializer, doctor values, logs, or API responses.
- Production PikaPods mode uses durable files under `/data/flatrate-live-chat/secrets/` (operator-provisioned).
- Precedence: direct env → explicit `*_FILE` → canonical `/data` file → fail closed.
- Explicit invalid `*_FILE` must not fall through to the canonical file.
- Secret file loaders reject empty/oversized/NUL/directory/symlink inputs; never log content.

## Egress probe (1.1.1 source)

- `GET /api/flatrate-live-chat/realtime/egress-probe` is admin-only.
- Outbound target is hardcoded (`https://realtime.flatrate.wiki/healthz`) — no SSRF input.
- Response is sanitized categories/status only (`Cache-Control: no-store`).

## Rollout / realtime security notes

- Hidden brand rooms are omitted from member list queries (404 on direct access).
- Subscription tokens are minted only for `$flatrate-live-<roomKey>` channels the actor may read; clients submit `roomKey` only.
- Fail closed without complete Centrifugo credentials: no publish, no client connect.
- Structured logs for publish/auth allow/deny never include message bodies or secrets.
- Immutable stables `1.0.0` / `1.1.0` remain; `1.1.1` tags/Packagist/production are not authorized by this source tranche.
- Historical Pusher Channels path is superseded and removed (never production-configured).
