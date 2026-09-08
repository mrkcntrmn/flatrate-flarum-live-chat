# Disposable runtime evidence (FORUM-CHAT-001C)

```text
RUNTIME_PATH=/home/ilove/dev/flarum-chat-001b-r1-runtime
DISPOSABLE_FLARUM_VERSION=1.8.19
DISPOSABLE_FLARUM_CORE_VERSION=1.8.19
PHP_VERSION=8.2.33
DB_ENGINE=mariadb:10.11
OWNED_PACKAGE_PATH=/home/ilove/dev/_worktrees/flatrate-flarum-live-chat-chat001c
DISPOSABLE_RUNTIME_PASS=true
LIVE_ROOMS_CREATED=0
PRODUCTION_INSTALL=false
PACKAGIST_PUBLISHED=false
PUSHER_SELECTED=false
productionPusherConfigured=false
```

## Required proofs (CHAT-001C)

```text
COMPOSER_RESOLVE_PASS=true
EXTENSION_BOOT_PASS=true
MIGRATIONS_PASS=true

ROOM_RECONCILE_PASS=true
ROOM_RECONCILE_IDEMPOTENT=true
FIRST_RECONCILE_CREATED=42
SECOND_RECONCILE_MUTATION_COUNT=0
CANONICAL_ROOM_COUNT=42

ROLLOUT_PROFILE=general-live-first
MEMBER_LIST_COUNT=1
STAFF_LIST_COUNT=42
MEMBER_TOYOTA_404=true
STAFF_TOYOTA_OK=true
GENERAL_LIVE_MEMBER_POST=true
GUEST_DENY=true
REALTIME_AUTH_FAIL_CLOSED_NO_CREDS=true
PUBLISH_SCOPE_ISOLATION_PASS=true
VISIBILITY_TRANSITION_PRESERVES_IDENTITY=true

TRANSPORT_IMPLEMENTATION=PUSHER_CHANNELS
transportDecision=PUSHER_CHANNELS
transportImplementationStatus=implemented/complete
transportExternalQualification=PENDING
TRANSPORT_SUBSCRIPTION_AUTH=implemented
SHARED_PUBLIC_PUSHER_CHANNEL=false

CHAT_ATTACHMENTS_INITIAL=false
CHAT_INDEXING=false
CHAT_EMAIL_NOTIFICATIONS=false
DM_COUPLING=false
```

## Notes

- Mounted C worktree at `/opt/flatrate-flarum-live-chat` for qualification.
- FakeRealtimePublisher file-backed publish-scope isolation PASS.
- No production Pusher credentials configured; auth endpoint fail-closed 403.
- Doctor CLI: `php flarum flatrate:live-chat:doctor` reports catalog/rollout/transport without secrets.
- Matrix log: runtime `evidence/http-chat001c-matrix.log`.
