# Configuration

Super Images is configured primarily from:

```text
config/super-images.php
```

Start from the commented example:

```bash
cp vendor/amici/craft-super-images/config/super-images.example.php config/super-images.php
```

The Control Panel **Settings** screen shows the effective values and lets you edit **derivative naming** when it is not locked by the PHP file.

---

## Top-level keys

| Key | Purpose |
|---|---|
| `enabled` | Master switch. `false` = no transforms; Twig falls back to original URLs |
| `defaultProfile` / `defaultFormat` | Used when Twig/CLI omit profile or format |
| `driver` | `auto` \| `libvips` \| `imagick` \| `gd` |
| `delivery` | Before-page-load generation + thumbnail placeholder |
| `autoGenerate` | Queue on Asset upload / replace / focal-point change |
| `sources` | Local path roots + remote host allow-list |
| `runtime` | Signed lazy-generate URL settings |
| `storage` | Adapters, markers, **naming** templates |
| `encoders` | Native encode quality / options |
| `optimizers` | Post-encode binaries (jpegoptim, cwebp, …) |
| `profiles` | Named variant × format sets |
| `volumes` / `folders` / `fields` | Scoped overrides |
| `cleanup` | Preview / generated retention |
| `policies` | Encode, geometry, safety, cleanup, fallback — see [Policies](./policies.md) |

---

## Profiles (the usual Twig surface)

```php
'profiles' => [
    'responsive' => [
        'formats' => ['jpg', 'webp'],
        'variants' => [
            'sm' => ['width' => 576],
            'md' => ['width' => 768],
            'lg' => ['width' => 992],
            'xl' => ['width' => 1280],
        ],
        'defaults' => [
            'position' => 'center-center',
            'mode' => 'fit',
            'jpegQuality' => 80,
        ],
    ],
],
```

In Twig:

```twig
{{ craft.superImages.img(asset, { profile: 'responsive', variant: 'md', format: 'webp' }) }}
```

Pass `operations` only when you want a **custom pipeline** (it replaces the profile variant steps).

---

## Sources

```php
'sources' => [
    'local' => [
        'enabled' => true,
        'allowedRoots' => ['@webroot/images', '@webroot/uploads'],
    ],
    'remote' => [
        'enabled' => true,
        'allowedHosts' => ['cdn.example.com', '*.picsum.photos'],
        'timeout' => 10,
        'maxBytes' => 25_000_000,
        'maxRedirects' => 3,
    ],
],
```

Remote URLs are denied unless the host is allow-listed.

---

## Delivery & runtime

```php
'delivery' => [
    'generateBeforePageLoad' => true, // omit to mirror Craft’s general setting
    'thumbnail' => [
        'enabled' => true,
        'width' => 32,
        'format' => 'jpg',
        'quality' => 50,
        'variant' => 'thumb',
    ],
],

'runtime' => [
    'enabled' => true, // required when generateBeforePageLoad is false
    'signingSecret' => App::env('SUPER_IMAGES_SIGNING_SECRET'),
    'urlTtl' => 3600,
    'maxWidth' => 4096,
    'maxHeight' => 4096,
    'maxPixels' => 20_000_000,
],
```

| Mode | What Twig emits for missing files |
|---|---|
| `generateBeforePageLoad = true` | Generate now → storage URL |
| `generateBeforePageLoad = false` | Signed runtime action URL (first hit generates) |

---

## Storage naming

Paths must change when operations/settings change, or you will see stale cached images.

Default:

```php
'storage' => [
    'naming' => [
        'assetPath' => '{folderHash}/{transformHash}/{assetId}/{basename}-{variant}.{ext}',
        'path' => '{identityShard}/{basename}-{variant}.{ext}',
        'transformHashLength' => 16,
        'includeVolumeInFolderHash' => false,
    ],
],
```

Full token list and recipes: [Storage](./storage.md).

Edit in **CP → Super Images → Settings**, or in PHP. If `storage.naming` exists in `config/super-images.php`, the file wins.

---

## Environment variables

| Variable | Use |
|---|---|
| `SUPER_IMAGES_SIGNING_SECRET` | Runtime URL HMAC (falls back to Craft `securityKey`) |
| `SUPER_IMAGES_STORAGE` | Default adapter handle |
| `SUPER_IMAGES_S3_*` / CDN URL | Remote storage |
| `JPEGOPTIM_PATH` / `CWEBP_PATH` / … | Optimizer binaries (see example config) |

Also see [Encoders & optimizers](./encoders-optimizers.md).

---

## Inspect effective config

```bash
php craft super-images/config --asset=123
php craft super-images/status
php craft super-images/doctor
```

---

## Related

- [Interactive demo](./demo.md) (`/super-images/config` page)
- [Policies](./policies.md)
- [Storage](./storage.md)
- Example file: `config/super-images.example.php`
