# Encoders & optimizers

## Mental model

```text
Driver processes pixels
        ↓
Encoder writes format bytes (native via GD / Imagick / Libvips)
        ↓
Optimizer optionally post-processes those bytes (jpegoptim, cwebp, …)
        ↓
Storage writes the final object
```

**Encoders** are native driver encoders. Configure quality under `encoders`.

**Optimizers** are optional external binaries. Configure which tool runs per format, and the binary path on each Ubuntu host.

---

## Encoder options

```php
'encoders' => [
    'jpeg' => ['quality' => 82],
    'webp' => ['quality' => 80],
    'avif' => ['quality' => 65],
],
```

---

## Optimizer config (Ubuntu)

```php
'optimizers' => [
    'enabled' => true,
    'binaries' => [
        'jpegoptim' => App::env('SUPER_IMAGES_JPEGOPTIM') ?: 'jpegoptim',
        'oxipng' => App::env('SUPER_IMAGES_OXIPNG') ?: 'oxipng',
        'optipng' => App::env('SUPER_IMAGES_OPTIPNG') ?: 'optipng',
        'pngquant' => App::env('SUPER_IMAGES_PNGQUANT') ?: 'pngquant',
        'cwebp' => App::env('SUPER_IMAGES_CWEBP') ?: 'cwebp',
        'avifenc' => App::env('SUPER_IMAGES_AVIFENC') ?: 'avifenc',
    ],
    'jpeg' => 'jpegoptim',
    'png' => 'oxipng',
    'webp' => null, // or 'cwebp'
    'avif' => null,
],
```

Typical Ubuntu paths after apt install: `/usr/bin/jpegoptim`, `/usr/bin/cwebp`, etc.

### Per-format override with an explicit binary

```php
'jpeg' => [
    'tool' => 'jpegoptim',
    'binary' => App::env('SUPER_IMAGES_JPEGOPTIM') ?: '/usr/bin/jpegoptim',
],
```

### Recommended `.env` on Ubuntu

```dotenv
SUPER_IMAGES_JPEGOPTIM=/usr/bin/jpegoptim
SUPER_IMAGES_CWEBP=/usr/bin/cwebp
SUPER_IMAGES_OXIPNG=/usr/bin/oxipng
```

If an env var is empty, Super Images falls back to the tool name and searches `PATH`.

### Resolution order

1. Per-format `binary` override (if set)
2. `optimizers.binaries[tool]`
3. Bare tool name on `PATH`

All execution goes through `ProcessRunner` (argument arrays only).

---

## Install missing tools (Ubuntu)

| Tool | apt |
|---|---|
| jpegoptim | `sudo apt-get install -y jpegoptim` |
| optipng | `sudo apt-get install -y optipng` |
| pngquant | `sudo apt-get install -y pngquant` |
| cwebp | `sudo apt-get install -y webp` |
| avifenc | `sudo apt-get install -y libavif-bin` |
| oxipng | often via cargo; or use optipng instead |
| php-gd | `sudo apt-get install -y php-gd` |
| php-imagick | `sudo apt-get install -y php-imagick` |
| libvips | `sudo apt-get install -y libvips42 libvips-dev` then `composer require jcupitt/vips` |

CP → **Encoders & Optimizers** shows green/red availability and an **i** tooltip with the apt command when something is missing.

---

## Verify

```bash
php craft super-images/doctor
```
