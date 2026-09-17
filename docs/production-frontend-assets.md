# Production frontend assets

```text
SOURCE=js/src
PRODUCTION_BUNDLE=js/dist/forum.js
FLARUM_SERVES_TRACKED_DIST=true
```

Flarum registers the compiled bundle via `extend.php`:

```php
(new Extend\Frontend('forum'))
    ->js(__DIR__ . '/js/dist/forum.js')
```

Production never executes `js/src` directly. After any `js/src` change that affects the forum frontend:

```bash
cd js
npm ci
NODE_OPTIONS=--openssl-legacy-provider npm test
NODE_OPTIONS=--openssl-legacy-provider npm run build
git add js/dist
```

CI rebuilds `js/dist` and fails if the working tree differs from the committed assets (`git diff --exit-code -- js/dist`).
