# Realtime — FlatRate Live Chat

```text
TRANSPORT_IMPLEMENTATION=PUSHER_CHANNELS
transportDecision=PUSHER_CHANNELS
transportImplementationStatus=implemented/complete
transportExternalQualification=PENDING
productionPusherConfigured=false
PUSHER_SELECTED=false
SHARED_PUBLIC_PUSHER_CHANNEL=false
```

## Architecture

Owned classes (explicit deps `pusher/pusher-php-server` + `pusher-js`; not ambient `flarum/pusher`):

- `PusherRealtimePublisher`
- `PusherChannelAuthorizer`
- `PusherClientConfig`
- `PerRoomChannelNamer`

Also kept: `NullRealtimePublisher` (fail-closed default) + `FakeRealtimePublisher` (tests/disposable).

## Channels

Private only:

```text
private-flatrate-live-<sha256('flatrate-live-chat|' + roomKey)>
```

Never a shared `public` fanout channel.

## Auth

```text
POST /api/flatrate-live-chat/realtime/auth
```

Body: `socket_id`, `channel_name`. Server resolves actor → channel → room → rollout ACL,
then signs only allowed private channels. Guests denied. Hidden brand rooms 404 for
ordinary members. Fail closed when credentials incomplete (no publish, no client connect).

## Events

Versioned FlatRate envelope on event name `flatrate.live`:

- `message.created` / `message.edited` / `message.deleted` / `room.updated`
- Allowlisted payload only (no IP / email / secrets / message body)

Ordering by persisted message id (`order`). HTTP history remains authoritative.
Client connects/authorizes/subscribes/unsubscribes/reconnects with multi-tab dedupe.

## Credentials

Disposable/local env vars (never committed):

```text
FLATRATE_LIVE_CHAT_PUSHER_KEY
FLATRATE_LIVE_CHAT_PUSHER_SECRET
FLATRATE_LIVE_CHAT_PUSHER_APP_ID
FLATRATE_LIVE_CHAT_PUSHER_CLUSTER
```

CHAT-001C does **not** authorize production Pusher credentials or Packagist publish.
