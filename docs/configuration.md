# Configuration

Primary source of truth for version-controlled projects:

```text
config/super-images.php
```

Craft loads this into the plugin Settings model (handle `super-images`). The Control Panel reads the **same** model — it is not a second config dialect.

Copy from:

```text
vendor/amici/craft-super-images/config/super-images.example.php
```

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
| `optimizers` | Binary tools + **paths** + per-format selection |
| `profiles` | Variants × formats |
| `volumes` / `folders` / `fields` | Scoped overrides |
| `cleanup` | Preview / obsolete retention |

Secrets and host-specific paths should use `App::env(...)`.

---

## Minimal example

```php
<?php

use craft\helpers\App;

return [
    'enabled' => true,
    'defaultProfile' => 'responsive',
    'defaultFormat' => 'webp',
    'driver' => 'auto',

    'delivery' => [
        'mode' => 'lazy',
    ],

    'runtime' => [
        'signingSecret' => App::env('SUPER_IMAGES_SIGNING_SECRET'),
        'urlTtl' => 3600,
    ],

    'storage' => [
        'default' => App::env('SUPER_IMAGES_STORAGE') ?: 'local',
        'adapters' => [
            'local' => [
                'type' => 'local',
                'path' => '@webroot/uploads/super-images',
                'baseUrl' => '@web/uploads/super-images',
            ],
        ],
    ],

    'optimizers' => [
        'enabled' => true,
        'binaries' => [
            'jpegoptim' => App::env('SUPER_IMAGES_JPEGOPTIM') ?: 'jpegoptim',
            'cwebp' => App::env('SUPER_IMAGES_CWEBP') ?: 'cwebp',
        ],
        'jpeg' => 'jpegoptim',
        'webp' => null,
    ],

    'profiles' => [
        'responsive' => [
            'formats' => ['jpg', 'webp'],
            'variants' => [
                'sm' => ['width' => 576],
                'md' => ['width' => 768],
                'lg' => ['width' => 992],
            ],
        ],
    ],
];
```

---

## Precedence

Rough resolution order for a request:

```text
explicit Twig/CLI options
  ↓
field overrides
  ↓
folder overrides
  ↓
volume overrides
  ↓
profile / variant / format defaults
  ↓
global settings
```

Use `php craft super-images/config --asset=123` to inspect effective config for an Asset.

---

## Environment variables (recommended)

| Variable | Use |
|---|---|
| `SUPER_IMAGES_SIGNING_SECRET` | Runtime URL HMAC (falls back to Craft `securityKey`) |
| `SUPER_IMAGES_STORAGE` | Default adapter handle |
| `SUPER_IMAGES_S3_*` / CDN URL | Remote storage |
| `SUPER_IMAGES_JPEGOPTIM` | Absolute path to jpegoptim |
| `SUPER_IMAGES_CWEBP` | Absolute path to cwebp |
| `SUPER_IMAGES_OXIPNG` | Absolute path to oxipng |
| `SUPER_IMAGES_OPTIPNG` | Absolute path to optipng |
| `SUPER_IMAGES_PNGQUANT` | Absolute path to pngquant |
| `SUPER_IMAGES_AVIFENC` | Absolute path to avifenc |

See [Encoders & optimizers](./encoders-optimizers.md) for path examples on macOS vs Ubuntu.
