# Runtime delivery

Super Images mirrors Craft’s `generateTransformsBeforePageLoad`.

## `generateBeforePageLoad`

```php
'delivery' => [
    // true  = generate missing files during Twig, emit storage/CDN URLs
    // false = emit signed action URL when missing (browser hits runtime endpoint)
    // omit  = use Craft::$app->config->general->generateTransformsBeforePageLoad
    'generateBeforePageLoad' => true,
],
```

| Setting | Missing file | URL in HTML |
|---|---|---|
| `true` | Generated **during the page request** | Storage/CDN URL |
| `false` | Generated when the browser hits the action | `/actions/super-images/runtime/generate?...` |

If the file already exists, Twig always emits the storage URL.

---

## Runtime endpoint (only when `generateBeforePageLoad` is false)

```php
'runtime' => [
    'enabled' => true,
    'signingSecret' => App::env('SUPER_IMAGES_SIGNING_SECRET'),
    'urlTtl' => 3600,
    'maxWidth' => 4096,
    'maxHeight' => 4096,
    'maxPixels' => 20_000_000,
],
```

Flow when deferred:

```text
Twig emits signed URL
        ↓
Browser requests /actions/super-images/runtime/generate?...
        ↓
Verify signature → generate → 302 → storage URL
```

If `runtime.enabled` is false and `generateBeforePageLoad` is also false, Super Images still sync-generates so templates do not 404.

---

## Thumbnail `src`

`picture()` can put a tiny server-generated storage URL in `<img src>` while full candidates stay in `srcset` / `<source>`. See [Twig](./twig.md#thumbnail-placeholder-src).

```php
'delivery' => [
    'generateBeforePageLoad' => true,
    'thumbnail' => [
        'enabled' => true,
        'width' => 32,
        'format' => 'jpg',
        'quality' => 50,
        'variant' => 'thumb',
    ],
],
```

---

## Locks

`GenerationLockService` prevents stampedes when many clients request the same missing derivative simultaneously.

---

## Security notes

- Do not put secrets in identity hashes, markers, or Twig output.
- Runtime limits reject oversized geometry requests.
- Local/remote sources still require allow-lists.
- CSRF is disabled on the runtime action because requests are GET-signed redirects from `<img>` tags.
