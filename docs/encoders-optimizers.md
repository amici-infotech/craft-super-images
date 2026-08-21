# Encoders & optimizers

**Rule of thumb:** use the **native encoder** plus **optimizer binaries** in config. Write a custom encoder only when you must replace the encode step — see [`examples/`](../examples/README.md).

## Mental model

```text
Driver processes pixels
        ↓
Encoder writes format bytes (GD / Imagick / Libvips)
        ↓
Optimizer optionally post-processes those bytes (jpegoptim, cwebp, …)
        ↓
Storage writes the final object
```

### `optimizeType`

```php
'optimizers' => [
    'enabled' => true,
    // job     = serve the file first; jpegoptim/optipng/etc. overwrite later via Craft queue
    // runtime = block until post-optimize finishes (useful for debugging / no queue)
    'optimizeType' => 'job',
    'jpeg' => 'jpegoptim',
    'png' => 'oxipng',
    'webp' => null,
    'avif' => null,
],
```

Same-format post-optimizers (`jpegoptim`, `optipng`, `oxipng`, `pngquant`) can be deferred with `job` **when Craft’s queue is running**. Without a queue worker, Super Images falls back to inline (`runtime`) so derivatives are still optimized.

Format converters (`cwebp`, `avifenc`) always run during generation so the stored object is already the correct type.

With `job`, the page can return storage URLs as soon as resize/encode finishes. The queue then reads the stored object (local disk or S3 download), optimizes, and overwrites the **same path/URL**.

---

## Encoder options

Top-level keys under each format (except `quality` and `stripMetadata`) are passed through as **encode extras** to the active driver / external tool.

```php
'encoders' => [
    'jpeg' => [
        'quality' => 82,          // 1–100
        'progressive' => false,   // progressive JPEG (Imagick / libvips / GD)
        // 'background' => '#ffffff', // used when flattening alpha for JPEG
    ],
    'jpg' => ['quality' => 82],
    'png' => [
        // 'pngCompression' => 6, // 0–9 (GD / Imagick / libvips)
    ],
    'webp' => [
        'quality' => 80,
        // Imagick native WebP (when not using cwebp):
        // 'method' => 4,          // 0–6 encode effort (higher = smaller/slower)
        // 'alphaQuality' => 80,   // alpha plane quality
        // 'lossless' => false,
        // Also used as cwebp -m when optimizers.webp = 'cwebp'
        'method' => 4,
    ],
    'avif' => [
        'quality' => 65,
        // Libvips native AVIF (and isolated `vips` binary path):
        // 0 = fastest / larger files (default in Super Images for libvips)
        // 4 = libvips upstream default (much slower on FPM/srcset)
        // 9 = slowest / smallest
        'effort' => 0,
    ],
],
```

`policies.encode` merges first, then per-format `encoders.*` wins:

```php
'policies' => [
    'encode' => [
        'stripMetadata' => true,
        'progressive' => false,
        'pngCompression' => 6,
    ],
],
```

### Tradeoffs (speed ↔ size ↔ quality)

| Option | Where | Range / default | Turn **down** (faster / larger) | Turn **up** (slower / smaller) | Notes |
|---|---|---|---|---|---|
| `quality` | jpeg / webp / avif | ~1–100; jpeg **82**, webp **80**, avif **65** | Lower Q → smaller + more artifacts | Higher Q → bigger + cleaner | Biggest visual lever. Profile `jpegQuality` overrides JPEG only. |
| `effort` | **avif** (libvips native / `vips` CLI) | **0–9**, Super Images default **0** | `0`–`1`: ~10× faster encode on typical thumbs/srcset | `4`–`9`: closer to libvips default; much slower under FPM | Does **not** change the quality scale; it spends more CPU to hit the same Q with a smaller file. Prefer `0` for runtime/on-demand generation; raise for offline CLI batches if you care about bytes. |
| `method` | **webp** (Imagick `webp:method`, and **cwebp** `-m`) | **0–6**, default **4** | Lower → faster encode, larger WebP | Higher → slower, usually smaller | Same idea as AVIF effort for WebP. |
| `alphaQuality` | webp (Imagick) | 1–100; defaults to `quality` | Lower → coarser alpha | Higher → cleaner transparency | Only when Imagick encodes WebP natively. |
| `lossless` | webp (Imagick) | `false` | Lossy (default) | `true` → large files, perfect pixels | Rarely what you want for photos. |
| `progressive` | jpeg | `false` | Baseline JPEG (faster encode/decode for tiny thumbs) | Progressive → better perceived load on large photos | Comes from `policies.encode` or `encoders.jpeg`. |
| `pngCompression` | png | **0–9**, default **6** | Lower → faster, larger PNG | Higher → slower, smaller | Deflate effort only; not “quality”. |
| `background` | jpeg (when source has alpha) | `#ffffff` | — | — | Flatten color before dropping alpha. |
| `stripMetadata` | all | `true` (policy) | Keep EXIF/ICC (larger; privacy) | Strip (default) | Identity/cache includes this flag. |

#### Practical recommendations

- **Dev / on-demand srcset (libvips):** keep `avif.effort => 0` (or omit; default is 0). Raising effort is what made full jpg+webp+avif galleries feel ~15s+ vs Imagick.
- **Production CLI pre-generate:** you can set `'effort' => 4` (or higher) for smaller AVIFs when TTFB does not matter.
- **WebP via cwebp:** tune `method` (and optional `arguments`) under `encoders.webp`; the optimizer path reads the same options.
- **Changing any of these** changes derivative identity → new files are generated (old ones become orphanable via cleanup).
- **PHP-FPM 502 with libvips:** see [Drivers](./drivers.md) — Super Images isolates libvips under FPM; keep `effort` low for on-demand work.

### Custom CLI arguments on encoders / optimizers

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

Per-format optimizer override with binary + arguments:

```php
'jpeg' => [
    'tool' => 'jpegoptim',
    'binary' => $_ENV['SUPER_IMAGES_JPEGOPTIM'] ?? '/usr/bin/jpegoptim',
    // Replaces the built-in recipe. Tokens: {input} {output} {quality} {effort} {method}
    'arguments' => [
        '--stdout' => true,
        '--strip-all' => true,
        '--max' => 85,
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

Optimizer-level `arguments` win over encoder-level `arguments` when both are set. When `arguments` is omitted, Super Images uses built-in recipes (jpegoptim stdout strip, cwebp with `-q` / `-m` / `-sharp_yuv`, etc.).

All execution goes through `ProcessRunner` (argument arrays only).

---

## Optimizer binaries

```php
'optimizers' => [
    'enabled' => true,
    'optimizeType' => 'runtime',
    'binaries' => [
        'jpegoptim' => $_ENV['SUPER_IMAGES_JPEGOPTIM'] ?? 'jpegoptim',
        'oxipng' => $_ENV['SUPER_IMAGES_OXIPNG'] ?? 'oxipng',
        'optipng' => $_ENV['SUPER_IMAGES_OPTIPNG'] ?? 'optipng',
        'pngquant' => $_ENV['SUPER_IMAGES_PNGQUANT'] ?? 'pngquant',
        'cwebp' => $_ENV['SUPER_IMAGES_CWEBP'] ?? 'cwebp',
        'avifenc' => $_ENV['SUPER_IMAGES_AVIFENC'] ?? 'avifenc',
    ],
    'jpeg' => 'jpegoptim',
    'png' => 'oxipng',
    'webp' => null,
    'avif' => null,
],
```

Resolution order: per-format `binary` override → `optimizers.binaries[tool]` → tool name on `PATH`.

CP → **Encoders & Optimizers** shows resolved paths and an **i** tooltip with the apt command when something is missing.

---

## Install missing tools (Ubuntu)

Live servers are assumed to be Ubuntu. CP install hints and doctor suggestions use `apt-get` only.

| Tool | apt |
|---|---|
| jpegoptim | `sudo apt-get install -y jpegoptim` |
| optipng | `sudo apt-get install -y optipng` |
| pngquant | `sudo apt-get install -y pngquant` |
| cwebp | `sudo apt-get install -y webp` |
| avifenc | `sudo apt-get install -y libavif-bin` |
| oxipng | often via cargo; or use optipng instead |
| php-gd | `sudo apt-get install -y php8.x-gd` then restart `php8.x-fpm` — full steps: [Drivers](./drivers.md#gd) |
| php-imagick | `sudo apt-get install -y php8.x-imagick` then restart FPM — [Drivers](./drivers.md#imagick) |
| libvips | `sudo apt-get install -y libvips42 libvips-dev libvips-tools libffi-dev`, enable `ffi.enable=true` in FPM php.ini, `composer require jcupitt/vips` — [Drivers](./drivers.md#libvips) |

### Recommended `.env` paths

```dotenv
SUPER_IMAGES_JPEGOPTIM=/usr/bin/jpegoptim
SUPER_IMAGES_CWEBP=/usr/bin/cwebp
SUPER_IMAGES_OXIPNG=/usr/bin/oxipng
# Libvips FPM isolation (especially useful on macOS / Herd):
# SUPER_IMAGES_VIPS_BINARY=/usr/bin/vips
# SUPER_IMAGES_PHP_BINARY=/usr/bin/php8.3
```

If an env var is empty, Super Images falls back to the tool name and searches `PATH`. Use `App::env('…')` in `config/super-images.php` (Craft loads `.env`).

---

## JPEG and alpha

JPEG does not support transparency. When the source has an alpha channel, all drivers **flatten onto a white background** (`#ffffff`) before JPEG encode so output matches WebP/AVIF visually (no “ghost” overlay).

Override the flatten colour via encoder `extra.background` if needed.

---

## Verify

```bash
php craft super-images/doctor
```

---

## Related

- [Drivers](./drivers.md)
- [Configuration](./configuration.md)
