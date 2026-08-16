# Storage

Derivative storage is **independent of Craft Volumes**. Volumes hold originals; Super Images adapters hold generated files.

---

## Mental model

1. Craft Asset / local path / remote URL → original
2. Super Images generates a derivative
3. File is written to a **storage adapter** (local disk or S3-compatible)
4. Twig/CLI get a public URL from that adapter

Nothing is stored in a `GeneratedImage` database table. Existence is checked via the file (and optional tiny markers for remote adapters).

---

## Local adapter

```php
'storage' => [
    'default' => 'local',
    'adapters' => [
        'local' => [
            'type' => 'local',
            'path' => '@webroot/transforms/super-images',
            'baseUrl' => '@web/transforms/super-images',
        ],
    ],
],
```

Tips:

- Prefer a path that will not collide with Craft template routes (e.g. `/transforms/…` or `/uploads/super-images`).
- The directory must be writable by PHP / the web server.

---

## S3-compatible (S3 / Spaces / R2)

Requires `aws/aws-sdk-php`.

```php
's3' => [
    'type' => 's3',
    'keyId' => App::env('SUPER_IMAGES_S3_KEY_ID'),
    'secret' => App::env('SUPER_IMAGES_S3_SECRET'),
    'bucket' => App::env('SUPER_IMAGES_S3_BUCKET'),
    'region' => App::env('SUPER_IMAGES_S3_REGION'),
    'endpoint' => App::env('SUPER_IMAGES_S3_ENDPOINT'), // optional
    'prefix' => 'derivatives/',
    'baseUrl' => App::env('SUPER_IMAGES_CDN_URL'),
],
```

Remote storage does **not** keep a permanent local image mirror. Tiny existence markers live under:

```text
@storage/super-images/markers
```

Never put markers under webroot.

```php
'storage' => [
    'markers' => [
        'enabled' => true,
        'path' => '@storage/super-images/markers',
    ],
],
```

---

## Why paths include a transform hash

Every derivative has a **generation identity** (SHA-256 of source + profile/variant/format + operations + encode options + driver + …).

Older layouts used only:

```text
{folderHash}/{assetId}/{basename}-{variant}.webp
```

That meant changing `sepia` threshold (or any other op) kept the **same path**, so the old file was reused until you cleared cache.

The **default** layout now includes a settings-aware segment:

```text
{folderHash}/{transformHash}/{assetId}/{basename}-{variant}.{ext}
```

| Segment | Meaning |
|---|---|
| `{folderHash}` | MD5 of the Craft volume folder path (groups related assets) |
| `{transformHash}` | First N characters of the generation identity (changes when ops/settings change) |
| `{assetId}` | Craft asset ID |
| `{basename}-{variant}.{ext}` | Readable filename |

Example:

```text
41762720c56668e667b056cfce41e4c6/9e98de8791b4f917/184704/hero-md.webp
```

Non-asset sources (local / remote) default to:

```text
{identityShard}/{basename}-{variant}.{ext}
```

Playground previews are prefixed with `preview/YYYYMMDD/`.

---

## Custom naming conventions

Configure under `storage.naming` (also editable in **CP → Super Images → Settings → Derivative naming**).

```php
'storage' => [
    'naming' => [
        // Craft Assets
        'assetPath' => '{folderHash}/{transformHash}/{assetId}/{basename}-{variant}.{ext}',
        // Local path / remote URL
        'path' => '{identityShard}/{basename}-{variant}.{ext}',
        // Length of {transformHash} / {identityShort} (8–64)
        'transformHashLength' => 16,
        // Include volume handle inside {folderHash}
        'includeVolumeInFolderHash' => false,
    ],
],
```

### Available tokens

| Token | Description |
|---|---|
| `{folderHash}` | MD5 of the Craft asset folder path |
| `{transformHash}` | First N chars of generation identity (ops/settings-aware) |
| `{identityShort}` | Alias of `{transformHash}` |
| `{transformFolderHash}` | `md5(folderPath + identity)` — single folder that mixes Craft folder + settings |
| `{identity}` | Full SHA-256 identity |
| `{identityShard}` | Two-level shard: `ab/cd` |
| `{assetId}` | Craft asset ID |
| `{basename}` | Original filename without extension |
| `{variant}` | Variant handle (`md`, `lg`, custom id, …) |
| `{profile}` | Profile handle |
| `{format}` / `{ext}` | Output format / file extension (`jpeg` → `jpg`) |
| `{namespace}` | Optional prefix (e.g. `preview/20260816`) |
| `{volume}` | Volume handle when available |

### Recipe ideas

**Compact single hash (folder = settings + Craft folder):**

```php
'assetPath' => '{transformFolderHash}/{assetId}/{basename}-{variant}.{ext}',
```

**Very readable (less opaque):**

```php
'assetPath' => '{volume}/{profile}/{variant}/{assetId}-{basename}.{ext}',
```

> If you omit every identity / transform token from `assetPath`, changing operations can reuse a stale file again. Prefer keeping `{transformHash}`, `{transformFolderHash}`, `{identity}`, or `{identityShard}` in the template.

If `storage.naming` is set in `config/super-images.php`, that file wins over the CP form.

---

## Volume overrides

Pick a different adapter (or profile) per Craft volume:

```php
'volumes' => [
    'images' => [
        'storage' => 's3',
        'profile' => 'responsive',
        'autoGenerate' => true,
    ],
],
```

---

## Related

- [Configuration](./configuration.md)
- [Control Panel](./control-panel.md) (naming UI)
- [Diagnostics & cleanup](./diagnostics.md)
