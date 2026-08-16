# Super Images

Image processing for Craft CMS 5 — transforms, formats, optimization, storage, and delivery.

```text
Resolve source → Process → Encode → Optimize → Validate → Store → Deliver
```

| Surface | Role |
|---|---|
| Twig (`craft.superImages`) | Delivery helpers (`url` / `img` / `picture` / `srcset`) |
| CLI / queue | Eager generation |
| Signed runtime URL | Lazy generation on first request |
| Playground (CP) | Preview under a `preview/` storage namespace |
| Control Panel | Diagnostics, encoders, naming, overview |
| Interactive demo | Clickable Twig lab (`demo/` → `templates/super-images/`) |

---

## Contents

1. [Installation](#installation)
2. [Quick start](#quick-start)
3. [Interactive demo](./demo.md)
4. [Architecture](#architecture)
5. [Configuration](./configuration.md)
6. [Policies](./policies.md)
7. [Encoders & optimizers](./encoders-optimizers.md)
8. [Twig & frontend](./twig.md)
9. [CLI & queue](./cli.md)
10. [Runtime delivery](./delivery.md)
11. [Storage & naming](./storage.md)
12. [Control Panel & Playground](./control-panel.md)
13. [Cleanup & diagnostics](./diagnostics.md)
14. [Extension API](./extension-api.md)

---

## Installation

```bash
composer require amici/craft-super-images
php craft plugin/install super-images
```

Copy the example config:

```bash
cp vendor/amici/craft-super-images/config/super-images.example.php config/super-images.php
```

Optional — install the interactive demo:

```bash
cp -R vendor/amici/craft-super-images/demo templates/super-images
```

Then open `/super-images`. Details: [Interactive demo](./demo.md).

Set environment variables for secrets and Ubuntu binary paths (see [Encoders & optimizers](./encoders-optimizers.md)).

---

## Quick start

### Twig (variable API)

```twig
{{ craft.superImages.img(asset, { profile: 'responsive', variant: 'md', format: 'webp' }) }}
{{ craft.superImages.url(asset, { variant: 'lg', format: 'webp' }) }}
{{ craft.superImages.picture(asset, { profile: 'responsive', formats: ['webp', 'jpg'], sizes: '100vw' }) }}
```

With `delivery.generateBeforePageLoad = true` (or Craft’s matching setting), Twig generates missing files and emits storage URLs.

With `generateBeforePageLoad = false`, Twig emits signed runtime URLs for missing files; the first browser hit generates and redirects to storage.

### Custom operations

```twig
{{ craft.superImages.img(asset, {
  format: 'jpg',
  operations: [
    { type: 'fit', width: 800 },
    { type: 'sepia', threshold: 80 },
  ],
  alt: entry.title,
}) }}
```

Passing `operations` replaces the profile variant pipeline — always start with geometry.

### CLI (eager)

```bash
php craft super-images/status
php craft super-images/generate --asset=123 --dry-run
php craft super-images/generate --volume=images --queue=1
php craft super-images/doctor
```

### Local path / remote CDN originals

```twig
{{ craft.superImages.url('/images/hero.png', { format: 'webp', variant: 'lg' }) }}
{{ craft.superImages.url('https://cdn.example.com/media/hero.jpg', { format: 'webp' }) }}
```

Remote hosts must be allow-listed under `sources.remote`.

---

## Architecture

### One pipeline

Every generation path (CLI, queue, runtime, Playground, Twig eager generate, explicit `generate()`) calls the same `GenerationService`.

### Deterministic identity + settings-aware paths

Each derivative has a SHA-256 **identity** (source + profile/variant/format + operations + encode options + driver + …).

Default storage paths include `{transformHash}` (from that identity) so changing ops/settings creates a **new file** instead of reusing a stale cache. Customize templates under `storage.naming` or in CP Settings — see [Storage](./storage.md).

There is **no** `GeneratedImage` database table.

### Delivery contract

| Mode | Twig emits |
|---|---|
| `generateBeforePageLoad` true | Storage URL (generate during Twig if missing) |
| `generateBeforePageLoad` false | Signed `/actions/super-images/runtime/generate?...` when missing |

When the file already exists, Twig always emits the storage URL.

---

## Related

- Example config: `config/super-images.example.php`
- Interactive demo package: `demo/`
- Planning docs: `plan/` (historical; product docs above are authoritative)
