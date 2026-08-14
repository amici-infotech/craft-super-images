# Runtime delivery

## Lazy mode flow

```text
Twig emits signed URL
        ↓
Browser requests /actions/super-images/runtime/generate?...
        ↓
Verify signature + expiry + limits
        ↓
Acquire generation lock (single-flight)
        ↓
Skip if already stored / marker exists
        ↓
GenerationService
        ↓
302 → final storage/CDN URL
```

Controller: `RuntimeController::actionGenerate`  
Anonymous access is allowed; authorization is the HMAC signature.

---

## Signing

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

If `signingSecret` is empty, Craft’s `securityKey` is used.

Signed params include exactly one source (`assetId` **or** `localPath` **or** `remoteUrl`) plus `profile`, `variant`, `format`, `exp`, `sig`.

---

## Eager / hybrid

With `delivery.mode = eager` (or current `hybrid`), Twig emits the final storage URL directly. Ensure derivatives are pre-generated (CLI/queue/autoGenerate) or the URL may 404 until generated elsewhere.

---

## Locks

`GenerationLockService` prevents stampedes when many clients request the same missing derivative simultaneously.

---

## Security notes

- Do not put secrets in identity hashes, markers, or Twig output.
- Runtime limits reject oversized geometry requests.
- Local/remote sources still require allow-lists.
- CSRF is disabled on the runtime action because requests are GET-signed redirects from `<img>` tags.
