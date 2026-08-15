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

**Encoders** are native driver encoders. Configure quality (and optional CLI args for external tools) under `encoders`.

**Optimizers** are optional external binaries. Configure which tool runs per format, binary paths, and custom CLI arguments.

### `optimizeType` (Imager-style)

```php
'optimizers' => [
    'enabled' => true,
    // job     = serve the file first; jpegoptim/optipng/etc. overwrite later via Craft queue
    // runtime = block until post-optimize finishes (useful for debugging)
    'optimizeType' => 'job',
    'jpeg' => 'jpegoptim',
    'png' => 'optipng',
    'webp' => 'cwebp', // format converter — always runs during generate
],
```

Same-format post-optimizers (`jpegoptim`, `optipng`, `oxipng`, `pngquant`) can be deferred. Format converters (`cwebp`, `avifenc`) always run during generation so the stored object is already the correct type.

With `job`, the page can return storage URLs as soon as resize/encode finishes. The queue then reads the stored object (local disk or S3 download), optimizes, and overwrites the **same path/URL**.

---

## Encoder options

```php
'encoders' => [
    'jpeg' => ['quality' => 82],
    'webp' => [
        'quality' => 80,
        'method' => 4, // passed to cwebp as -m when using PNG→cwebp
    ],
    'avif' => ['quality' => 65],
],
```

### Custom CLI arguments on encoders

When a format uses an external tool (for example `optimizers.webp = 'cwebp'`), you can override the full argument list after the binary. Prefer a **key/value** map:

```php
'encoders' => [
    'webp' => [
        'quality' => 80,
        'arguments' => [
            '-q' => '{quality}',
            '-m' => 6,
            '-sharp_yuv' => true,
            '-o' => '{output}',
            '_' => ['{input}'], // trailing positionals
        ],
    ],
],
```

A flat token list still works: `['-q', '{quality}', '{input}', '-o', '{output}']`.

Optimizer-level `arguments` win over encoder-level `arguments` when both are set.

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

### Per-format override with binary + arguments

```php
'jpeg' => [
    'tool' => 'jpegoptim',
    'binary' => App::env('SUPER_IMAGES_JPEGOPTIM') ?: '/usr/bin/jpegoptim',
    // Replaces the built-in recipe. Tokens: {input} {output} {quality} {effort} {method}
    'arguments' => [
        '--stdout' => true,
        '--strip-all' => true,
        '--max' => 85,       // → --max 85
        // '--max=' => 85,   // → --max=85
        '_' => ['{input}'],
    ],
],
```

Rules for key/value maps:

| Value | Result |
|---|---|
| `true` or `''` | Flag only (`--strip-all`) |
| `false` or `null` | Skipped |
| scalar | Flag + value as two argv tokens (`--max`, `85`) |
| key ending in `=` | Single token (`--max=85`) |
| `'_'` / `'positional(s)'` | Trailing positionals list |
| integer keys | Positional token (value only) |

`arguments` may also be a flat list or a whitespace-separated string. Alias key: `args`.

When `arguments` is omitted, Super Images uses built-in recipes (jpegoptim stdout strip, cwebp with `-q` / `-m` / `-sharp_yuv`, etc.).

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
