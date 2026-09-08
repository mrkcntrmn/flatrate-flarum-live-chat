# Architecture — FlatRate Live Chat

## Package identity

```text
COMPOSER=flatrate/flarum-live-chat
EXTENSION_ID=flatrate-live-chat
NAMESPACE=FlatRate\LiveChat\
UPSTREAM=xelson/flarum-ext-chat@v1.1.5@a7489ac183764eef12969135d6b665ac5eb18272
```

## Room model

Durable FlatRate identity (not Neon numeric `chat.id`, not Flarum tag id/slug):

```text
room_key UNIQUE
(scope_type, scope_key) UNIQUE
```

Canonical catalog (embedded `resources/room-catalog.json`):

- 41 brand rooms: `roomKey={boardKey}-live`, `scopeType=board`, `scopeKey={boardKey}`
- 1 General Live: `community-general-live`, `scopeType=navigation-group`, `scopeKey=community`

GM Live and CDJR Live are independent of child brand rooms. No mirroring.

## Authorization

`Auth\ChatAuthorization` is enforced server-side on API handlers:

- guests: no post; no message history
- members: read/post permitted public canonical rooms only
- members: cannot create/rename/delete/mutate scope
- suspended: cannot post
- sender always from actor (spoof rejected)
- type=0 private/group chat disabled (`DM_COUPLING=false`)

## Provisioner

`Provisioner\RoomProvisioner` modes: `validate` | `dry-run` | `reconcile`.

Default `allowWrites=false` (DRY_RUN_ONLY). Reconcile requires explicit allowWrites for disposable runtimes only.

## Realtime

```text
RealtimePublisher
NullRealtimePublisher (default)
FakeRealtimePublisher (tests)
PerRoomChannelNamer
```

```text
REALTIME_IMPLEMENTATION_DECISION=PENDING
PUSHER_SELECTED=false
SHARED_PUBLIC_PUSHER_CHANNEL=false
```

Events are published to isolated per-room channel keys. No shared `public` fanout.

## Content / media / indexing

- Plain text / basic markdown via TextFormatter; script re-exec removed
- `CHAT_ATTACHMENTS_INITIAL=false` — no FoF Upload integration
- `CHAT_INDEXING=false` — noindex on chat frontend
- `CHAT_IP_PERSISTENCE=false`
- `CHAT_EMAIL_NOTIFICATIONS=false`

## Flarum 2 boundary

Preserve `room_key` / `scope_type` / `scope_key` across core upgrades. Do not bind durable identity to Neon numeric ids or Flarum tag ids.
