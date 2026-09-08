# Rollout — FlatRate Live Chat

```text
CANONICAL_ROOM_COUNT=42
ROLLOUT_PROFILE=general-live-first
CHAT001C_STATUS=implementation-complete
PRODUCTION_INSTALL_AUTHORIZED=false
LIVE_ROOMS_CREATED=0
```

## Dimensions (not a single `enabled` boolean)

| Dimension | Values | Meaning |
| --- | --- | --- |
| canonical | present in embedded catalog | Room exists in the 42-room set |
| visibility | `visible` \| `hidden` | Server-side list/API/history/realtime exposure |
| audience | `members` \| `staff-preview` | Who may access when visible/hidden rules apply |

Hidden is **server-side**: ordinary members must not see brand rooms in list, API,
history, realtime auth, search, unread, or CTAs. Prefer **404** over revealing 403
for direct URL/API guesses.

## general-live-first

| Room | visibility | audience |
| --- | --- | --- |
| `community-general-live` | visible | members |
| all 41 brand rooms | hidden | staff-preview |

Staff preview: `canPreviewHiddenChatRooms(actor)` — admin **or** moderator.
Suspended users are denied posting and realtime subscription even if staff.

## Visibility transitions

Changing visibility/audience must **not** change `roomKey` / id / scope / messages.
Provisioner reconcile still targets 42 rooms idempotently and may apply rollout
dimensions without rewriting durable identity.

## Routes

Canonical family: `/live/{roomKey}`

Examples: `/live/community-general-live`, `/live/toyota-live`

Guests: history/post/realtime denied (CHAT-001B guest-safe policy preserved).
