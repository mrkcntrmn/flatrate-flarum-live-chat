# Realtime — FlatRate Live Chat

```text
NEXT_VERSION=1.1.1
SOURCE_IMPLEMENTED_TARGET=1.1.1
RC_DISTRIBUTED=false
STABLE_RELEASED=1.1.0
PRODUCTION_DEPLOYED=false
TRANSPORT_IMPLEMENTATION=CENTRIFUGO_SELF_HOSTED
transportDecision=CENTRIFUGO_SELF_HOSTED
transportImplementationStatus=stable-distributed-qualified (1.1.0); 1.1.1 source in progress
transportExternalQualification=PASS (infra); PikaPods in-pod egress via admin probe (1.1.1)
productionCentrifugoConfigured=false
PUSHER_SELECTED=false
SHARED_PUBLIC_REALTIME_CHANNEL=false
```

Immutable releases: `1.0.0`, `1.1.0-rc.1`, `1.1.0`. This document describes **source** work toward `1.1.1`. It does not claim RC/stable Packagist publication or production deploy.

## Architecture

Self-hosted Centrifugo via owned classes (explicit deps `firebase/php-jwt` + `guzzlehttp/guzzle` + JS `centrifuge`):

- `CentrifugoRealtimePublisher`
- `CentrifugoClientConfig`
- `CentrifugoSecretResolver`
- `CentrifugoChannelNamer`
- `RealtimeTokenIssuer` / `RsaRealtimeTokenIssuer`
- `RealtimeEgressProbe` (admin-only fixed-target healthz)

Also kept: `NullRealtimePublisher` (fail-closed default) + `FakeRealtimePublisher` (tests/disposable).

HTTP history remains authoritative; realtime is best-effort acceleration (publish never throws).

## Production configuration model (PikaPods / zero-custom-env)

PikaPods does **not** provide arbitrary custom environment variables. Production must work with:

```text
ZERO custom PikaPods environment variables
```

### Canonical non-secret defaults

When URL env vars are absent:

```text
DEFAULT_WEBSOCKET_URL=
wss://realtime.flatrate.wiki/connection/websocket

DEFAULT_PUBLISH_URL=
https://realtime.flatrate.wiki/api/publish

issuer=flatrate-forum
audience=flatrate-realtime
connection TTL=300
subscription TTL=300
JWT algorithm=RS256
```

Strict host/path/TLS validation is unchanged.

### Canonical durable secret files

Operator-provisioned only (never created or committed by the package):

```text
/data/flatrate-live-chat/secrets/centrifugo-edge-key
/data/flatrate-live-chat/secrets/centrifugo-api-key
/data/flatrate-live-chat/secrets/centrifugo-jwt-private-key.pem
```

Preferred modes: directory `0700`, files `0600` (never world-readable/writable). Runtime operability uses `is_readable()`.

### Secret precedence (fail-closed)

For each secret (edge key, API key, JWT private key):

```text
1. direct environment value
2. explicit *_FILE environment path
3. canonical /data fallback file
4. missing → fail closed
```

If an explicit `*_FILE` path is set but invalid/unreadable, do **not** silently fall through to the canonical file.

Optional file-path env vars (generic Docker/K8s mounts — **not** the PikaPods dependency):

```text
FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY_FILE
FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY_FILE
FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY_FILE
```

### Generic env / secret-mount deployment

Platforms that inject env still work: direct `EDGE_KEY` / `API_KEY` / `JWT_PRIVATE_KEY` (and optional URL overrides) retain precedence over files.

## Admin egress probe

```text
GET /api/flatrate-live-chat/realtime/egress-probe
```

- Authenticated **admin-only**
- Read-only; no body; no client-supplied URL (hardcoded `https://realtime.flatrate.wiki/healthz`)
- TLS verify on; redirects disabled; connect ≤2s; total ≤5s
- Sanitized JSON only (`ok`, `reachable`, `httpStatus`, `tlsVerified`, `durationMs`, or `category`)
- `Cache-Control: no-store`
- No secrets, IPs, bodies, headers, or exception strings

## Tokens / channels / envelope

Unchanged from `1.1.0`:

```text
POST /api/flatrate-live-chat/realtime/connect-token
POST /api/flatrate-live-chat/realtime/subscription-token
```

Private channels `$flatrate-live-<roomKey>`; envelope v2; forum attributes never expose edge/API/JWT/publish URL/paths/sources.

## Disposable env (optional overrides)

```text
FLATRATE_LIVE_CHAT_CENTRIFUGO_WS_URL
FLATRATE_LIVE_CHAT_CENTRIFUGO_PUBLISH_URL
FLATRATE_LIVE_CHAT_CENTRIFUGO_EDGE_KEY
FLATRATE_LIVE_CHAT_CENTRIFUGO_API_KEY
FLATRATE_LIVE_CHAT_CENTRIFUGO_JWT_PRIVATE_KEY
FLATRATE_LIVE_CHAT_CENTRIFUGO_*_FILE
FLATRATE_LIVE_CHAT_CENTRIFUGO_DISABLED
FLATRATE_LIVE_CHAT_ALLOW_INSECURE_REALTIME
FLATRATE_LIVE_CHAT_FAKE_REALTIME
```

## Historical note

Pusher Channels was never production-configured and is removed. Keep `1.0.0` / `1.1.0` immutable.
