# Drivers

Super Images processes pixels with **libvips**, **Imagick**, or **GD**.

Live / production installs are documented for **Ubuntu** (`apt`). Doctor hints and CP suggestions use the same commands. Local macOS (Herd / Homebrew) notes are included where they differ.

```php
// config/super-images.php
'driver' => 'auto', // or libvips | imagick | gd
```

| Preference | Behaviour |
|---|---|
| `auto` | First usable driver in order **libvips → Imagick → GD** |
| `libvips` / `imagick` / `gd` | Prefer that driver; if unavailable, log a warning and fall through the same order |

Only **one** driver runs per request. You can still install Imagick **and** libvips so auto (or fallback) can use either.

Verify anytime:

```bash
php craft super-images/doctor
```

CP → **Super Images → Diagnostics** shows the same checks, plus install hints when something is missing. Full FAQs for each driver are in this page.

---

## Choose a driver

| Driver | Best for | Needs |
|---|---|---|
| **libvips** | Fast large batches, modern formats | System libvips + `jcupitt/vips` + **FFI** enabled for FPM |
| **Imagick** | Watermarks, text, sepia, many filters | `php-imagick` for FPM |
| **GD** | Minimal footprint / shared hosts | `php-gd` for FPM |

Replace `8.3` below with your PHP minor version (`php -v`). CLI and FPM often use different ini files — always check **FPM** for the site.

```bash
php -v
php -i | grep "Loaded Configuration File"
# FPM (typical Ubuntu):
# /etc/php/8.3/fpm/php.ini
sudo systemctl restart php8.3-fpm
```

On **Laravel Herd** (macOS), edit the PHP ini for the active version in Herd’s UI (or `herd ini`), then restart Herd / PHP.

---

## GD

### Install (Ubuntu)

1. Install the extension for your PHP version:

```bash
sudo apt-get update
sudo apt-get install -y php8.3-gd
# or meta package:
# sudo apt-get install -y php-gd
```

2. Restart PHP-FPM:

```bash
sudo systemctl restart php8.3-fpm
```

3. Confirm **CLI** and **web** both see GD:

```bash
php -m | grep -i gd
```

Create a short `phpinfo()` script behind the same vhost/FPM pool and confirm a **gd** section exists.

4. Prefer GD in config (optional):

```php
'driver' => 'gd',
```

Or leave `'driver' => 'auto'` — GD is used only when libvips and Imagick are unavailable.

5. Re-check:

```bash
php craft super-images/doctor
```

Doctor should show **GD** as Available (and **selected** if it won preference/fallback).

### FAQ — GD

**Doctor: “Not available on this host” for GD.**  
Install `php8.x-gd` for the **same** PHP major.minor FPM runs, then restart FPM. Installing GD only for CLI does not help the website.

**Site works in CLI doctor but not in CP / Twig.**  
CLI and FPM load different `php.ini` / `conf.d`. Enable `gd.so` under `/etc/php/8.x/fpm/conf.d/` and restart `php8.x-fpm`.

**Formats feel limited / quality differs.**  
GD is the baseline driver. Prefer Imagick or libvips for sharper downscales and richer operations (see [Feature notes](#feature-notes)).

---

## Imagick

### Install (Ubuntu)

1. Install ImageMagick + the PHP extension:

```bash
sudo apt-get update
sudo apt-get install -y php8.3-imagick
# or:
# sudo apt-get install -y php-imagick
```

That pulls ImageMagick libraries as dependencies on Ubuntu.

2. Restart PHP-FPM:

```bash
sudo systemctl restart php8.3-fpm
```

3. Confirm the module loads without MagickWand errors:

```bash
php -m | grep -i imagick
php -r 'echo class_exists("Imagick") ? "ok\n" : "missing\n";'
```

Also confirm Imagick in a web `phpinfo()` for the FPM pool.

4. Prefer Imagick (optional):

```php
'driver' => 'imagick',
```

5. Re-check:

```bash
php craft super-images/doctor
```

### FAQ — Imagick

**Doctor: Imagick not available for this SAPI.**  
Install `php8.x-imagick` matching FPM, enable it under `/etc/php/8.x/fpm/conf.d/`, restart FPM. Compare `php -m` (CLI) vs `phpinfo()` (web).

**Startup warning: MagickWand shared library version mismatch / cannot open shared object.**  
The `imagick.so` was built against a different ImageMagick than the one on disk. Fix by reinstalling matching packages:

```bash
sudo apt-get install --reinstall -y php8.3-imagick imagemagick libmagickwand-dev
sudo systemctl restart php8.3-fpm
```

If the module still fails to load, Imagick is unavailable until the shared libraries match.

**Doctor says Imagick available, but I still get libvips.**  
With `'driver' => 'auto'`, libvips wins when usable. Pin `'driver' => 'imagick'` to force Imagick.

**Need watermarks / text / sepia.**  
Use Imagick (or ensure Imagick is installed as fallback). See [Feature notes](#feature-notes).

---

## Libvips

Libvips needs **three** pieces, not just Composer:

1. Native **libvips** on the OS (`libvips.so.42` / `libvips.42.dylib`)
2. PHP package **`jcupitt/vips`** in the Craft project (`composer require jcupitt/vips`)
3. **FFI enabled** for the PHP-FPM SAPI (`ffi.enable=true`)

Optional but recommended under FPM: the **`vips` CLI** so Super Images can isolate heavy work from the FPM worker (avoids nginx **502** / SIGSEGV on some platforms, especially macOS).

### Install (Ubuntu) — step by step

#### 1. System libraries + CLI

```bash
sudo apt-get update
sudo apt-get install -y libvips42 libvips-dev libvips-tools libffi-dev
which vips
vips --version
```

#### 2. PHP binding

From the Craft project root:

```bash
composer require jcupitt/vips
```

Confirm:

```bash
php -r 'require "vendor/autoload.php"; echo class_exists("Jcupitt\\Vips\\Image") ? "ok\n" : "missing\n";'
```

#### 3. Enable FFI for FPM (and CLI if you use `craft` CLI heavily)

Edit the **FPM** ini (example for 8.3):

```bash
sudo nano /etc/php/8.3/fpm/php.ini
```

Set:

```ini
ffi.enable = true
```

On PHP **8.3+**, also set:

```ini
zend.max_allowed_stack_size = -1
```

Optional pool env (limits libvips thread fan-out under FPM), e.g. `/etc/php/8.3/fpm/pool.d/www.conf`:

```ini
env[VIPS_CONCURRENCY] = 1
```

Restart FPM:

```bash
sudo systemctl restart php8.3-fpm
```

Confirm FPM-facing settings (CLI alone is not enough):

```bash
php -i | grep -E 'ffi.enable|max_allowed_stack'
# Prefer checking via a phpinfo() page served by the same pool as Craft.
```

#### 4. Select libvips in config

```php
'driver' => 'auto',    // prefers libvips when usable
// or
'driver' => 'libvips',
```

#### 5. Verify

```bash
php craft super-images/doctor
```

Expect **Libvips** Available · selected (when preferred), and **Libvips FPM isolation** pass under web SAPI when `vips` or the PHP worker is reachable.

Optional overrides in `.env` (Ubuntu paths):

```dotenv
SUPER_IMAGES_VIPS_BINARY=/usr/bin/vips
SUPER_IMAGES_PHP_BINARY=/usr/bin/php8.3
# Debug only — force in-process FFI (can crash FPM):
# SUPER_IMAGES_VIPS_ISOLATE=0
```

Prefer generating large batches via CLI / queue rather than syncing dozens of derivatives in one web request.

### Install (macOS / Herd / Homebrew)

```bash
brew install vips
composer require jcupitt/vips
```

Enable `ffi.enable=true` (and `zend.max_allowed_stack_size=-1` on PHP 8.3+) for the **Herd / FPM** PHP, not only CLI. Then set absolute paths if FPM’s `PATH` does not include Homebrew:

```dotenv
SUPER_IMAGES_VIPS_BINARY=/opt/homebrew/bin/vips
SUPER_IMAGES_PHP_BINARY=/opt/homebrew/opt/php@8.4/bin/php
```

Restart Herd / PHP after ini changes.

### FAQ — Libvips

**Doctor: Libvips not available / php-vips binding missing.**  
Run `composer require jcupitt/vips` in the Craft project. The Composer package alone is not enough without system libvips + FFI.

**Doctor: FFI disabled / FFI extension not loaded.**  
Enable the FFI extension and set `ffi.enable=true` in **FPM** `php.ini`, then restart FPM. Ubuntu package: `php8.x-ffi` if packaged separately.

**“Unable to open library …” / native library did not load.**  
Install `libvips42` (and usually `libvips-dev`) or `brew install vips`. The PHP class can exist while the shared library is missing. With `driver => auto`, Super Images falls back to Imagick, then GD. If libvips fails mid-request after selection, it is marked unusable for the rest of that request and generation replans on Imagick/GD.

**Nginx 502 only on pages that generate images; FPM log shows `exited on signal 11 (SIGSEGV)`.**  
That is a native crash inside libvips/FFI (PHP cannot catch it). CLI often still works. Super Images isolates libvips under `fpm-fcgi` / `cgi-fcgi`: prefer the **`vips` binary** for fit/fill/crop + encode; otherwise one PHP CLI worker (`bin/libvips-worker.php`). Install `libvips-tools` (or Homebrew `vips`), keep `ffi.enable=true`, and avoid `SUPER_IMAGES_VIPS_ISOLATE=0` on production FPM.

**Doctor: “Libvips FPM isolation” warn — neither the vips binary nor a PHP CLI worker responded.**  
Install the `vips` CLI on PATH, ensure a PHP CLI binary works with FFI + php-vips, or set `SUPER_IMAGES_VIPS_BINARY` / `SUPER_IMAGES_PHP_BINARY` in `.env`.

**I set `driver => libvips` but Imagick/GD is selected.**  
Libvips was not fully usable (binding, FFI, isolation under FPM, or `.so`/dylib). Check doctor rows for Libvips / FPM isolation; Craft logs a fallback warning.

**AVIF encodes are very slow under FPM.**  
Keep AVIF effort at `0` for on-demand work (Super Images default). See [Encoders & optimizers](./encoders-optimizers.md).

---

## Use Imagick and libvips together

Both can be installed on the same PHP. Only one is **selected** per request.

1. Complete [Imagick](#imagick) install.
2. Complete [Libvips](#libvips) install (system libs + FFI + optional `vips` CLI).
3. Set `'driver' => 'auto'` (prefers libvips) or pin `'driver' => 'imagick'` / `'libvips'`.
4. Run `php craft super-images/doctor` — you should see both drivers Available.

If the preferred driver is missing, Super Images logs a warning and falls through **libvips → Imagick → GD**.

---

## Feature notes

| Feature | Typical driver |
|---|---|
| fit / crop / fill / resize / scale / flip | All |
| rotate with background | Imagick preferred |
| sepia / saturation | Imagick |
| watermark (text or image), overlay, text | Imagick |
| blur / sharpen / brightness / contrast | All (options differ) |

Sepia `threshold` is **0–100**; ~**80** is the classic look.

---

## Related

- [Diagnostics](./diagnostics.md)
- [Configuration](./configuration.md)
- [Encoders & optimizers](./encoders-optimizers.md)
- [Control Panel](./control-panel.md)
