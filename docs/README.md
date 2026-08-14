# Super Images

Image processing infrastructure for Craft CMS 5 — transforms, formats, optimization, storage, and delivery.

```text
Resolve source → Process → Encode → Optimize → Validate → Store → Deliver
```

| Surface | Role |
|---|---|
| Twig (`craft.superImages`) | Plan delivery URLs only (no processing on render) |
| CLI / queue | Eager generation |
| Signed runtime URL | Lazy generation on first request |
| Playground (CP) | Preview under a separate `preview/` storage namespace |
| Control Panel | Diagnostics + overview over the same config model |

---

## Contents

1. [Installation](#installation)
2. [Quick start](#quick-start)
3. [Architecture](#architecture)
4. [Configuration](./configuration.md)
5. [Policies](./policies.md)
6. [Encoders & optimizers](./encoders-optimizers.md)
7. [Twig & frontend](./twig.md)
8. [CLI & queue](./cli.md)
9. [Runtime delivery](./delivery.md)
10. [Storage](./storage.md)
11. [Control Panel & Playground](./control-panel.md)
12. [Cleanup & diagnostics](./diagnostics.md)
13. [Extension API](./extension-api.md)

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

Set environment variables for secrets and Ubuntu binary paths (see [Encoders & optimizers](./encoders-optimizers.md)).

---

## Quick start

### Twig (variable API)

```twig
{{ craft.superImages.img(asset, { profile: 'responsive', variant: 'md', format: 'webp' }) }}
{{ craft.superImages.url(asset, { variant: 'lg', format: 'webp' }) }}
{{ craft.superImages.picture(asset, { profile: 'responsive', formats: ['webp', 'jpg'], sizes: '100vw' }) }}
```

With `delivery.mode = lazy`, these emit signed runtime URLs. The first browser hit generates the derivative and redirects to storage.

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

---

## Architecture

### One pipeline

Every generation path (CLI, queue, runtime, Playground, explicit `generate()`) calls the same `GenerationService`.

### Deterministic identity

Derivative paths are derived from a stable identity hash. There is **no** `GeneratedImage` database table.

### Delivery contract

| Mode | Twig emits |
|---|---|
| `lazy` (default) | Signed `/actions/super-images/runtime/generate?...` |
| `eager` | Final storage/CDN URL |
| `hybrid` | Storage URL today (same as eager) |

Normal Twig render never checks storage existence, markers, or remote HEAD.

---

## Related

- Example config: `config/super-images.example.php`
- Planning docs: `plan/` (historical; product docs above are authoritative for Twig variable API)
