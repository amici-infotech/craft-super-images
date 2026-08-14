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

**Encoders** today are native driver encoders. Configure quality / strip-metadata style options under `encoders`.

**Optimizers** are optional external binaries. Configure which tool runs per format, and **where that binary lives on each machine**.

`jpegoptim`, `cwebp`, `oxipng`, etc. are **optimizers** (or re-encoders used as optimizers), not the primary encoder registry.

---

## Encoder options

```php
'encoders' => [
    'jpeg' => ['quality' => 82],
    'webp' => ['quality' => 80],
    'avif' => ['quality' => 65],
],
```

These feed `EncodeOptions` for the selected driver. If the active driver cannot encode a format, generation fails for that unit (see Playground / `supportsFormat()`).

---

## Optimizer config

```php
'optimizers' => [
    'enabled' => true,

    // Host-specific paths (use env vars)
    'binaries' => [
        'jpegoptim' => App::env('SUPER_IMAGES_JPEGOPTIM') ?: 'jpegoptim',
        'oxipng' => App::env('SUPER_IMAGES_OXIPNG') ?: 'oxipng',
        'optipng' => App::env('SUPER_IMAGES_OPTIPNG') ?: 'optipng',
        'pngquant' => App::env('SUPER_IMAGES_PNGQUANT') ?: 'pngquant',
        'cwebp' => App::env('SUPER_IMAGES_CWEBP') ?: 'cwebp',
        'avifenc' => App::env('SUPER_IMAGES_AVIFENC') ?: 'avifenc',
    ],

    // Which tool to use per format (null = skip optimizer)
    'jpeg' => 'jpegoptim',
    'png' => 'oxipng',
    'webp' => null,   // or 'cwebp'
    'avif' => null,   // or 'avifenc'
],
```

### Per-format override with an explicit binary

```php
'jpeg' => [
    'tool' => 'jpegoptim',
    'binary' => App::env('SUPER_IMAGES_JPEGOPTIM') ?: '/usr/bin/jpegoptim',
],
```

---

## Different paths on macOS vs Ubuntu

Binaries often live in different places:

| Tool | macOS (Homebrew) | Ubuntu (apt) |
|---|---|---|
| jpegoptim | `/opt/homebrew/bin/jpegoptim` or `/usr/local/bin/jpegoptim` | `/usr/bin/jpegoptim` |
| cwebp | `/opt/homebrew/bin/cwebp` | `/usr/bin/cwebp` |
| oxipng | `/opt/homebrew/bin/oxipng` | depends on install |
| optipng | `/opt/homebrew/bin/optipng` | `/usr/bin/optipng` |

### Recommended approach: env vars per environment

**`.env` (local macOS)**

```dotenv
SUPER_IMAGES_JPEGOPTIM=/opt/homebrew/bin/jpegoptim
SUPER_IMAGES_CWEBP=/opt/homebrew/bin/cwebp
SUPER_IMAGES_OXIPNG=/opt/homebrew/bin/oxipng
```

**`.env` (Ubuntu server)**

```dotenv
SUPER_IMAGES_JPEGOPTIM=/usr/bin/jpegoptim
SUPER_IMAGES_CWEBP=/usr/bin/cwebp
SUPER_IMAGES_OXIPNG=/usr/bin/oxipng
```

**`config/super-images.php` (same file everywhere)**

```php
'optimizers' => [
    'enabled' => true,
    'binaries' => [
        'jpegoptim' => App::env('SUPER_IMAGES_JPEGOPTIM') ?: 'jpegoptim',
        'cwebp' => App::env('SUPER_IMAGES_CWEBP') ?: 'cwebp',
        'oxipng' => App::env('SUPER_IMAGES_OXIPNG') ?: 'oxipng',
    ],
    'jpeg' => 'jpegoptim',
    'png' => 'oxipng',
    'webp' => 'cwebp', // optional re-optimize after native encode
    'avif' => null,
],
```

If an env var is empty, Super Images falls back to the tool name and searches `PATH`.

### Resolution order

For a tool like `jpegoptim`:

1. Per-format `binary` override (if set)
2. `optimizers.binaries['jpegoptim']`
3. Bare `jpegoptim` on `PATH`

All execution goes through `ProcessRunner` (argument arrays only — no shell strings).

---

## Enabling cwebp for WebP

Native drivers already encode WebP. Setting `webp => 'cwebp'` runs libwebp afterwards as an optional pass:

```php
'optimizers' => [
    'enabled' => true,
    'binaries' => [
        'cwebp' => App::env('SUPER_IMAGES_CWEBP') ?: 'cwebp',
    ],
    'webp' => 'cwebp',
],
```

If the binary is missing, the pipeline keeps the native-encoded bytes (optimizer failure is soft).

---

## Verify on a machine

```bash
php craft super-images/doctor
```

Look for **Optimizer binaries** — it lists available vs missing tools and resolved paths.

In the CP: **Super Images → Encoders & Optimizers**.

Programmatically:

```php
Plugin::getInstance()->getBinaryResolver()->inventory();
```

---

## Supported tools

| Tool | Typical formats |
|---|---|
| `jpegoptim` | jpeg/jpg |
| `oxipng` | png |
| `optipng` | png |
| `pngquant` | png |
| `cwebp` | webp |
| `avifenc` | avif |

Unknown tool names raise `OptimizerUnavailableException` only when building a command for that tool; selection falls back to a no-op optimizer when the binary is unavailable.
