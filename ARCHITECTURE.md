# Architecture — FlatRate Live Chat

## Package identity

```text
COMPOSER=flatrate/flarum-live-chat
EXTENSION_ID=flatrate-live-chat
NAMESPACE=FlatRate\LiveChat\
UPSTREAM_SOURCE=xelson/flarum-ext-chat
UPSTREAM=xelson/flarum-ext-chat@v1.1.5@a7489ac183764eef12969135d6b665ac5eb18272
```

`UPSTREAM.md` SOURCE must remain `xelson/flarum-ext-chat`. Owned package identity
is separate and must not overwrite provenance fields.

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

Default `allowWrites=false` (DRY_RUN_ONLY). Reconcile requires explicit allowWrites for disposable runtimes only (`FLATRATE_LIVE_CHAT_ALLOW_WRITES=1`).

## Realtime boundary

```text
RealtimePublisher abstraction = implemented
per-room publish scope = implemented
NullRealtimePublisher (default production-safe)
FakeRealtimePublisher (tests / disposable HTTP matrix)
transport selection = PENDING (CHAT-001C)
subscription transport AuthZ = PENDING (CHAT-001C)
```

```text
REALTIME_IMPLEMENTATION_DECISION=PENDING
TRANSPORT_SUBSCRIPTION_AUTH=PENDING
PUSHER_SELECTED=false
PUSHER_AUTHORIZED=false
PUSHER_CONFIGURED=false
SHARED_PUBLIC_PUSHER_CHANNEL=false
```

Events are published to isolated per-room channel keys. No shared `public` fanout.
Pusher is not the selected solution; transport remains undecided until CHAT-001C.

Disposable runtimes may set `FLATRATE_LIVE_CHAT_FAKE_REALTIME=1` (optional
`FLATRATE_LIVE_CHAT_FAKE_REALTIME_FILE`) to prove publish-scope isolation through
the real HTTP post path without configuring a production transport.

## Content / media / indexing

- Plain text / basic markdown via TextFormatter; script re-exec removed
- `CHAT_ATTACHMENTS_INITIAL=false` — no FoF Upload integration
- `CHAT_INDEXING=false` — noindex on chat frontend
- `CHAT_IP_PERSISTENCE=false`
- `CHAT_EMAIL_NOTIFICATIONS=false`

## Flarum 2 boundary

Preserve `room_key` / `scope_type` / `scope_key` across core upgrades. Do not bind durable identity to Neon numeric ids or Flarum tag ids.
