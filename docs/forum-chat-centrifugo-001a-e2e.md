# FORUM-CHAT-CENTRIFUGO-001A — Disposable Flarum E2E (R2)

Status: **PASS** — disposable qualification only. Not production deployment.

```text
CHAT_R2_E2E_TESTED_SHA=62a3a20c12665d22b3d91c59399aad7346ec0a26
INFRA_DEPLOY_RUNTIME_SHA=bc6a38717759c5040f9a83bf7f2ede5cd053d2f4
INFRA_PR_HEAD=7462c7741d8fb7967c1db4cfce344a3f3595291e
REALTIME_HOST=https://realtime.flatrate.wiki
DISPOSABLE_RUNTIME=/home/ilove/dev/flarum-chat-centrifugo-001a-r2-runtime
EVIDENCE=/home/ilove/dev/_evidence/forum-chat-centrifugo-001a-r2/
```

## Stack

| Item | Value |
|------|-------|
| Flarum | 1.8.19 |
| PHP | 8.2.33 |
| MariaDB | 10.11.19 |
| centrifuge.js | 5.7.3 |
| Transport | CENTRIFUGO_SELF_HOSTED |
| FakeRealtime | false |

## Doctor (disposable)

```text
CANONICAL_ROOM_COUNT=42
BRAND_ROOM_COUNT=41
GENERAL_ROOM_COUNT=1
MEMBER_VISIBLE_ROOMS=1
STAFF_PREVIEW_ROOMS=41
TRANSPORT_DECISION=CENTRIFUGO_SELF_HOSTED
RUNTIME_CONFIGURED=true
CREDENTIALS_COMPLETE=true
URLS_TLS_SAFE=true
JWT_ALGORITHM=RS256
JWT_ISSUER=flatrate-forum
JWT_AUDIENCE=flatrate-realtime
```

No lifecycle hardcodes (`NEXT_VERSION`, production flags, qualification pending).

## 42-room reconcile

| Run | Result |
|-----|--------|
| First | FIRST_RECONCILE_CREATED=42 |
| Second | SECOND_RECONCILE_CREATED=0, SECOND_RECONCILE_MUTATIONS=0 |
| Visibility | memberVisible=1, staffPreview=41 |

## Token HTTP matrix

| Case | Result |
|------|--------|
| Guest connect | 403 |
| Member connect | 200 |
| Member General Live subscription | 200 |
| Member Toyota subscription | 404 |
| Staff Toyota subscription | 200 |
| Client-supplied channel parameter | 400 |
| Legacy `/realtime/auth` | 410 |
| Suspended connect | 403 |

## Persist → publish → receive

| Check | Result |
|-------|--------|
| MESSAGE_HTTP_POST | PASS |
| DB_ROW_EXISTS | PASS |
| BUSINESS_REALTIME_EMISSION_COUNT | 1 |
| CLIENT_EVENT_RECEIVED | PASS |
| EVENT_ENVELOPE_VERSION | 2 |
| EVENT_ID_PRESENT | true |
| EVENT_ROOM_KEY | community-general-live |
| EVENT_MESSAGE_ID_MATCH | true |
| REALTIME_CONTENT_LEAK | false |

## Repeated edits

Two `message.edited` events for the same messageId/roomKey with **distinct** `eventId` values; HTTP history final content equals edit #2.

## Outage degradation (disposable-only)

Mechanism: rewrite container `/etc/hosts` so `realtime.flatrate.wiki` → `127.0.0.1` / `::1` (IPv4 pin cleared). Public POC Centrifugo left running.

| Check | Result |
|-------|--------|
| Publish unreachable from disposable | PASS |
| HTTP message post | PASS |
| DB persistence | PASS |
| Realtime warning logged (`realtime.publish.error`) | PASS (9 lines) |
| Request duration | 604ms (&lt; 8s) |
| Message rollback | false |
| Outage message in history after restore | PASS |
| Post-recovery publish + receive | PASS |

Disposable also pins Cloudflare **A** records into `/etc/hosts` outside outage windows so Guzzle does not prefer a broken container IPv6 path.

## Client / reconnect / security

| Check | Result |
|-------|--------|
| Client direct publish | DENY (Centrifugo 103) |
| Reconnect smoke | PASS |
| Guest post | 403 |
| Suspended post | 403 |
| Member Toyota history | 404 |
| Staff Toyota history | 200 |
| PUSHER_RUNTIME_REFERENCE_COUNT | 0 |

## Related unit coverage (package CI)

Not substituted for live E2E, but exercised on the same SHA:

- single emission authority
- transport retry same `eventId` / idempotency key
- malformed 2xx deny
- channel/payload room mismatch deny
- frontend `eventId` dedupe

## Exact-head CI

```text
CHAT_EXACT_HEAD_CI=PASS @ 62a3a20c12665d22b3d91c59399aad7346ec0a26
```

## Safety

```text
PIKAPODS_MUTATION=false
PRODUCTION_MUTATION=false
LIVE_ROOMS_CREATED=0
PACKAGIST_PUBLICATION=false
CHAT_PR_3_MERGED=false
```

No secrets (JWT, edge/API keys, private keys, cookies, DB passwords) are recorded in this document.
