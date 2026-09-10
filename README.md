# FlatRate Live Chat

FlatRate.wiki-owned Flarum 1.x live-chat package.

Derived from [`xelson/flarum-ext-chat`](https://github.com/Xelson/flarum-ext-chat) (Neon Chat) v1.1.5 under MIT. Upstream attribution for Push-EDX / Xelson is preserved in `LICENSE` and `UPSTREAM.md`.

| Identity | Value |
| --- | --- |
| Composer | `flatrate/flarum-live-chat` |
| Extension ID | `flatrate-live-chat` |
| Namespace | `FlatRate\LiveChat\` |
| Upstream pin | `a7489ac183764eef12969135d6b665ac5eb18272` (`v1.1.5`) |
| Rollout | `general-live-first` (General Live member-visible; brands staff-preview) |
| Transport | Centrifugo self-hosted (`1.1.0` stable; `1.1.1` source adds PikaPods file-backed secrets + egress probe) |

**Not** an official Neon / Xelson release. Stock Packagist `xelson/flarum-ext-chat` must not be installed on FlatRate production.

Canonical routes: `/live/{roomKey}`.

### Deployment models

1. **Generic env / secret-mount** — set Centrifugo URL/secret env vars (or `*_FILE` mounts) as usual.
2. **FlatRate / PikaPods canonical `/data` secrets** — PikaPods does **not** inject arbitrary custom env. With no realtime env vars, the package defaults websocket/publish URLs to the pinned FlatRate hosts and loads secrets from:

```text
/data/flatrate-live-chat/secrets/centrifugo-edge-key
/data/flatrate-live-chat/secrets/centrifugo-api-key
/data/flatrate-live-chat/secrets/centrifugo-jwt-private-key.pem
```

Operator-provisioned only. See `REALTIME.md`.

Admin-only egress probe (after install/enable): `GET /api/flatrate-live-chat/realtime/egress-probe`.

Release boundary: `1.1.0` remains the immutable stable on Packagist. `1.1.1` tags/Packagist/production deploy are **not** claimed by this source work.

See `UPSTREAM.md`, `SECURITY.md`, `ARCHITECTURE.md`, `ROLLOUT.md`, and `REALTIME.md`.

```bash
composer validate --strict
composer update --no-interaction
vendor/bin/phpunit
cd js && npm ci && npm test && npm run build
php flarum flatrate:live-chat:doctor   # inside a Flarum app with the extension enabled
```
