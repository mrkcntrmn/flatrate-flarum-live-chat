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
visibility  (visible|hidden)
audience    (members|staff-preview)
```

Canonical catalog (embedded `resources/room-catalog.json`):

- 41 brand rooms: `roomKey={boardKey}-live`, `scopeType=board`, `scopeKey={boardKey}`
- 1 General Live: `community-general-live`, `scopeType=navigation-group`, `scopeKey=community`

```text
CANONICAL_ROOM_COUNT=42
```

GM Live and CDJR Live are independent of child brand rooms. No mirroring.

## Rollout

See `ROLLOUT.md`. Profile `general-live-first`: General Live member-visible;
all brand rooms hidden/staff-preview. Not a single `enabled` boolean.

## Authorization

`Auth\ChatAuthorization` is enforced server-side on API handlers:

- guests: no post; no message history; no realtime
- members: read/post only rooms visible under rollout (General Live first)
- staff preview: `canPreviewHiddenChatRooms(actor)` (admin + moderator)
- members: cannot create/rename/delete/mutate scope
- suspended: cannot post or subscribe
- sender always from actor (spoof rejected)
- type=0 private/group chat disabled (`DM_COUPLING=false`)
- Prefer 404 over revealing 403 for hidden room guesses

## Routes

Canonical family: `/live/{roomKey}` (examples: `/live/community-general-live`, `/live/toyota-live`).

## Provisioner

`Provisioner\RoomProvisioner` modes: `validate` | `dry-run` | `reconcile`.

Default `allowWrites=false` (DRY_RUN_ONLY). CLI reconcile requires explicit
`FLATRATE_LIVE_CHAT_ALLOW_WRITES=1` for disposable/operator shell environments.

Production PikaPods has no supported app shell. Use the admin-only HTTP API:

```text
GET  /api/flatrate-live-chat/admin/rooms/reconcile-preview
POST /api/flatrate-live-chat/admin/rooms/reconcile
```

That path binds catalog/state SHA + confirmation, creates 0→42 in one DB
transaction, fail-closes on partial/extra/drift, and no-ops when already
reconciled. It constructs a write-enabled provisioner only inside the trusted
controller — the service-provider default remains `allowWrites=false`.

Reconcile applies rollout visibility/audience without rewriting durable identity.

## Realtime boundary

See `REALTIME.md`.

```text
NEXT_VERSION=1.1.1
SOURCE_IMPLEMENTED_TARGET=1.1.1
RC_DISTRIBUTED=false
STABLE_RELEASED=1.1.0
PRODUCTION_DEPLOYED=false
TRANSPORT_IMPLEMENTATION=CENTRIFUGO_SELF_HOSTED
transportDecision=CENTRIFUGO_SELF_HOSTED
transportImplementationStatus=stable-distributed-qualified (1.1.0); 1.1.1 source in progress
transportExternalQualification=PASS (infra); admin egress probe for in-pod reachability
productionCentrifugoConfigured=false
SHARED_PUBLIC_REALTIME_CHANNEL=false
```

`NullRealtimePublisher` when credentials incomplete; `FakeRealtimePublisher` for
disposable HTTP matrix (`FLATRATE_LIVE_CHAT_FAKE_REALTIME=1`).

## Content / media / indexing

- Plain text / basic markdown via TextFormatter; script re-exec removed
- `CHAT_ATTACHMENTS_INITIAL=false` — no FoF Upload integration
- `CHAT_INDEXING=false` — noindex on chat frontend
- `CHAT_IP_PERSISTENCE=false`
- `CHAT_EMAIL_NOTIFICATIONS=false`

## Doctor

```bash
php flarum flatrate:live-chat:doctor
```

Reports catalog/rollout/transport without secrets.

## Flarum 2 boundary

Preserve `room_key` / `scope_type` / `scope_key` across core upgrades. Do not bind durable identity to Neon numeric ids or Flarum tag ids.
