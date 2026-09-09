# Realtime — FlatRate Live Chat

```text
NEXT_VERSION=1.1.0
TRANSPORT_IMPLEMENTATION=CENTRIFUGO_SELF_HOSTED
transportDecision=CENTRIFUGO_SELF_HOSTED
transportImplementationStatus=implemented/complete
transportExternalQualification=PENDING
productionCentrifugoConfigured=false
PUSHER_SELECTED=false
SHARED_PUBLIC_REALTIME_CHANNEL=false
```

## Architecture

Self-hosted Centrifugo via owned classes (explicit deps `firebase/php-jwt` + `guzzlehttp/guzzle` + JS `centrifuge`):

- `CentrifugoRealtimePublisher`
- `CentrifugoClientConfig`
- `CentrifugoChannelNamer`
- `RealtimeTokenIssuer` / `RsaRealtimeTokenIssuer`

Also kept: `NullRealtimePublisher` (fail-closed default) + `FakeRealtimePublisher` (tests/disposable).

HTTP history remains authoritative; realtime is best-effort acceleration (publish never throws).

## Channels

Private Centrifugo channels only:

```text
$flatrate-live-<roomKey>
```

Example: `$flatrate-live-community-general-live`

Server builds channel names from `roomKey`. Clients send `roomKey` only — never arbitrary channel strings. Strict parse rejects unknown roomKeys.

## Auth / tokens

```text
POST /api/flatrate-live-chat/realtime/connect-token
POST /api/flatrate-live-chat/realtime/subscription-token
```

- Guests / suspended denied (403)
- Subscription body: `{ "roomKey": "..." }` only (reject `channel` params)
- Ordinary member + hidden brand room → 404
- RS256 JWTs: connection claims `sub,iss,aud,exp`; subscription adds exact `channel`
- Cache-Control: no-store
- Legacy `POST .../realtime/auth` → 410 Gone

## Events

Versioned FlatRate envelope published as Centrifugo publication data:

- `message.created` / `message.edited` / `message.deleted` / `room.updated`
- Allowlisted payload only (no IP / email / secrets / message body)

Ordering by persisted message id (`order`). Client connects / token-refreshes / subscribes by roomKey with multi-tab dedupe. Client publish is disabled.

## Credentials

Disposable/local env vars (never committed):

```text
FLATRATE_LIVE_CHAT_CENTRIFUGO_WS_URL
FLATRATE_LIVE_CHAT_CENTRIFUGO_PUBLISH_URL
FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY
FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY
FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY
FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY_FILE
FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_ISSUER          # default flatrate-forum
FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_AUDIENCE        # default flatrate-realtime
FLATRATE_LIVE_CHAT_CENTRIFUGO_CONNECTION_TTL      # default 300
FLATRATE_LIVE_CHAT_CENTRIFUGO_SUBSCRIPTION_TTL    # default 300
FLATRATE_LIVE_CHAT_CENTRIFUGO_DISABLED
FLATRATE_LIVE_CHAT_ALLOW_INSECURE_REALTIME        # localhost http/ws tests only
FLATRATE_LIVE_CHAT_FAKE_REALTIME                  # FakeRealtimePublisher
```

Publish edge headers: `X-FlatRate-Realtime-Key` + `X-API-Key`. TLS required for non-localhost (`https` publish, `wss`/`https` websocket). Forum attributes expose only transport/connect/configured/websocketUrl/token endpoints — never edgeKey, apiKey, privateKey, or publish URL.

## Frontend

JS dependency: `centrifuge` (v5.x CommonJS/ESM; webpack 4 / Flarum bundling). If a newer major fails to bundle, pin the last webpack-4-compatible release here.

## Historical note (superseded)

Pusher Channels (`pusher/pusher-php-server`, `pusher-js`, hashed `private-flatrate-live-<sha256>` channels) was implemented in CHAT-001C as a transport candidate but **never production-configured** and is fully removed in CENTRIFUGO-001A. Do not restore Pusher credentials or Packagist publish for transport.

CHAT-CENTRIFUGO-001A does **not** authorize production Centrifugo credentials, tags, or Packagist publish. Keep `1.0.0` immutable; next release target is `1.1.0`.
