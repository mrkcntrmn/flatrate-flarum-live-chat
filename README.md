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
| Transport | Pusher Channels (implemented; production credentials not configured) |

**Not** an official Neon / Xelson release. Stock Packagist `xelson/flarum-ext-chat` must not be installed on FlatRate production.

Canonical routes: `/live/{roomKey}`.

See `UPSTREAM.md`, `SECURITY.md`, `ARCHITECTURE.md`, `ROLLOUT.md`, and `REALTIME.md`.

```bash
composer validate --strict
composer update --no-interaction
vendor/bin/phpunit
cd js && npm ci && npm test && npm run build
php flarum flatrate:live-chat:doctor   # inside a Flarum app with the extension enabled
```
