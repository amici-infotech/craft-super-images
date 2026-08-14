# Configuration

Primary source of truth:

```text
config/super-images.php
```

Copy from:

```text
vendor/amici/craft-super-images/config/super-images.example.php
```

Every key in the example file is commented. Live servers are assumed to be **Ubuntu**.

---

## Top-level keys

| Key | Purpose |
|---|---|
| `enabled` | Master switch |
| `defaultProfile` / `defaultFormat` | Twig/CLI defaults |
| `driver` | `auto` \| `libvips` \| `imagick` \| `gd` |
| `delivery.mode` | `lazy` \| `eager` \| `hybrid` |
| `autoGenerate` | Queue on Asset upload/replace/focal-point |
| `sources` | Local allow-list + remote host allow-list |
| `runtime` | Signed URL TTL, signing secret, size limits |
| `storage` | Adapters + marker path |
| `encoders` | Native encode options (quality, etc.) |
| `optimizers` | Binary tools + paths + per-format selection |
| `profiles` | Variants × formats |
| `volumes` / `folders` / `fields` | Scoped overrides |
| `cleanup` | Preview / obsolete retention |

---

## Responsive formats

Default profile uses JPG + WebP. AVIF stays commented until you are ready:

```php
'formats' => [
    'jpg',
    'webp',
    // 'avif',
],
```

---

## Environment variables

| Variable | Use |
|---|---|
| `SUPER_IMAGES_SIGNING_SECRET` | Runtime URL HMAC (falls back to Craft `securityKey`) |
| `SUPER_IMAGES_STORAGE` | Default adapter handle |
| `SUPER_IMAGES_S3_*` / CDN URL | Remote storage |
| `SUPER_IMAGES_JPEGOPTIM` | Path to jpegoptim (Ubuntu: `/usr/bin/jpegoptim`) |
| `SUPER_IMAGES_CWEBP` | Path to cwebp |
| `SUPER_IMAGES_OXIPNG` | Path to oxipng |
| `SUPER_IMAGES_OPTIPNG` | Path to optipng |
| `SUPER_IMAGES_PNGQUANT` | Path to pngquant |
| `SUPER_IMAGES_AVIFENC` | Path to avifenc |

See [Encoders & optimizers](./encoders-optimizers.md).

---

## Inspect effective config

```bash
php craft super-images/config --asset=123
```
