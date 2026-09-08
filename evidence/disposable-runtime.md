# Disposable runtime evidence (FORUM-CHAT-001B)

```text
DISPOSABLE_FLARUM_VERSION=1.8.19
DISPOSABLE_RUNTIME_PASS=PARTIAL
LIVE_ROOMS_CREATED=0
PRODUCTION_INSTALL=false
```

## Passed in B

- Composer resolve includes `flarum/core` 1.8.19
- PHPUnit security/domain suite: 33 tests (AuthZ negatives, catalog 42, realtime isolation, floodgate binding, serializer allowlists, script re-exec removal, type=0 disable)
- RoomProvisioner dry-run expects 42; reconcile blocked without allowWrites
- Realtime: `NullRealtimePublisher` default; decision PENDING

## Not executed in B (explicit)

- Full docker Flarum HTTP API matrix against a booted forum
- Production Pusher configuration
- Live room reconcile writes

CHAT-001C may deepen disposable HTTP qualification after transport selection.
