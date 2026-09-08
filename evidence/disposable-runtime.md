# Disposable runtime evidence (FORUM-CHAT-001B.R1)

```text
RUNTIME_PATH=/home/ilove/dev/flarum-chat-001b-r1-runtime
DISPOSABLE_FLARUM_VERSION=1.8.19
DISPOSABLE_FLARUM_CORE_VERSION=1.8.19
PHP_VERSION=8.2.33
DB_ENGINE=mariadb:10.11
OWNED_PACKAGE_PATH=/home/ilove/dev/flatrate-flarum-live-chat
DISPOSABLE_RUNTIME_PASS=true
LIVE_ROOMS_CREATED=0
PRODUCTION_INSTALL=false
PACKAGIST_PUBLISHED=false
```

## Required proofs (§37)

```text
COMPOSER_RESOLVE_PASS=true
EXTENSION_BOOT_PASS=true
MIGRATIONS_PASS=true

ROOM_RECONCILE_PASS=true
ROOM_RECONCILE_IDEMPOTENT=true
FIRST_RECONCILE_CREATED=42
SECOND_RECONCILE_MUTATION_COUNT=0

TOTAL_ROOM_COUNT=42
BRAND_ROOM_COUNT=41
GENERAL_ROOM_COUNT=1

HTTP_AUTHZ_MATRIX_PASS=true
MESSAGE_PERSISTENCE_PASS=true
SENDER_SPOOF_PASS=true
SUSPENDED_USER_PASS=true
FLOODGATE_RUNTIME_PASS=true

SERIALIZER_RUNTIME_ALLOWLIST_PASS=true
IP_PERSISTENCE_DISABLED=true
IP_DATABASE_WRITE_COUNT=0
SCRIPT_REEXECUTION_RUNTIME_PASS=true

PRIVATE_CHAT_HTTP_DISABLED=true

PUBLISH_SCOPE_ISOLATION_PASS=true
TRANSPORT_SUBSCRIPTION_AUTH=PENDING
REALTIME_IMPLEMENTATION_DECISION=PENDING

MUST_FIX_REMAINING_COUNT=0
```

## Environment notes

- Installed `flatrate/flarum-live-chat` from local path mount (not stock Neon / Packagist).
- Canonical rooms: `community-general-live`, `gm-live`, `cdjr-live`, `toyota-live` present.
- Absent: `start-here-live`, `general-shop-discussion-live`, `technician-topics-live`.
- Duplicate `room_key` / `(scope_type,scope_key)` rejected by UNIQUE indexes.
- Disposable actors: guest, member_a, member_b, suspended_member, moderator, admin (credentials only under runtime `evidence/`, gitignored).
- Floodgate proven via Flarum session `AccessToken` (ApiKey auth intentionally sets `bypassThrottling`).
- Realtime: `FakeRealtimePublisher` file-backed publish-scope isolation PASS; no Pusher.
- Browser smoke: CLI/curl only (`BROWSER_SMOKE=CLI_CURL_ONLY`); Chrome DevTools MCP not required for R1 closure.

Machine-readable copy: runtime `evidence/http-authz-matrix-results.json` + `evidence/http-authz-matrix.log`.
