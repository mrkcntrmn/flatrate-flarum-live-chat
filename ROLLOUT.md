# Rollout — FlatRate Live Chat

```text
CANONICAL_ROOM_COUNT=46
ROLLOUT_PROFILE=general-live-first
CHAT001C_STATUS=implementation-complete
PRODUCTION_INSTALL_AUTHORIZED=false
LIVE_ROOMS_CREATED=0
```

## Dimensions (not a single `enabled` boolean)

| Dimension | Values | Meaning |
| --- | --- | --- |
| canonical | present in embedded catalog | Room exists in the 46-room set |
| visibility | `visible` \| `hidden` | Server-side list/API/history/realtime exposure |
| audience | `members` \| `staff-preview` | Who may access when visible/hidden rules apply |

Hidden is **server-side**: ordinary members must not see brand rooms in list, API,
history, realtime auth, search, unread, or CTAs. Prefer **404** over revealing 403
for direct URL/API guesses.

## general-live-first

| Room | visibility | audience |
| --- | --- | --- |
| `community-general-live` | visible | members |
| all 45 brand rooms | hidden | staff-preview |

Staff preview: `canPreviewHiddenChatRooms(actor)` — admin **or** moderator.
Suspended users are denied posting and realtime subscription even if staff.

That staff-preview contract is Brand-room visibility only. It does not grant
the pinned General Live MAIN surface.

## Pinned MAIN Live gates

These settings do not authorize or hide the existing General Live room.
`general_live_enabled` remains the only operational room gate.

| Setting | Absent | Purpose |
| --- | --- | --- |
| `flatrate-live-chat.general_live_enabled` | enabled | Master kill switch for General Live |
| `flatrate-live-chat.general_live_admin_preview_enabled` | disabled | Pinned MAIN for Flarum admins only |
| `flatrate-live-chat.general_live_user_enabled` | disabled | Pinned MAIN for signed-in members |

Effective forum attribute: `flatrate-live-chat.main_live_available`.

```text
MAIN_LIVE_AVAILABLE =
  GENERAL_LIVE_ENABLED
  AND AUTHENTICATED
  AND CHAT_VIEW_PERMISSION
  AND (
    USER_LIVE_ENABLED
    OR (IS_ADMIN AND ADMIN_PREVIEW_ENABLED)
  )
```

Admin Preview does not include moderators. User Live includes admins because
they are signed-in members. The row-level LIVE control remains a personal
preference and is not stored as either rollout setting.

Operational rollback without a package downgrade:

```text
User Live OFF        removes member pinned rollout
Admin Preview OFF    removes admin-only preview
General Live OFF     emergency master kill switch
```

## Visibility transitions

Changing visibility/audience must **not** change `roomKey` / id / scope / messages.

CLI provisioner reconcile (disposable shells) targets 46 rooms and may apply
rollout dimensions without rewriting durable identity.

Production reconcile uses admin HTTP endpoints only:

```text
GET  /api/flatrate-live-chat/admin/rooms/reconcile-preview
POST /api/flatrate-live-chat/admin/rooms/reconcile
```

Production path is fail-closed: initial 0→46 create or already-reconciled no-op.
Partial, extra, or drifted states require human review (no auto-heal).

## Routes

Canonical family: `/live/{roomKey}`

Examples: `/live/community-general-live`, `/live/toyota-live`

Guests: history/post/realtime denied (CHAT-001B guest-safe policy preserved).
