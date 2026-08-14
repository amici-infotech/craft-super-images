# Super Images

Image processing infrastructure for Craft CMS 5 — transforms, formats, optimization, storage, and delivery.

Super Images is **not** a thin wrapper around Craft asset transforms. It is a generation pipeline:

```text
Resolve source → Process → Encode → Optimize → Validate → Store → Deliver
```

| Surface | Role |
|---|---|
| Twig / filters | Plan delivery URLs only (no processing on render) |
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
5. [Encoders & optimizers](./encoders-optimizers.md)
6. [Twig & frontend](./twig.md)
7. [CLI & queue](./cli.md)
8. [Runtime delivery](./delivery.md)
9. [Storage](./storage.md)
10. [Control Panel & Playground](./control-panel.md)
11. [Cleanup & diagnostics](./diagnostics.md)
12. [Extension API](./extension-api.md)

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

Then set environment variables for secrets and host-specific binary paths (see [Encoders & optimizers](./encoders-optimizers.md)).

---

## Quick start

### Twig (lazy by default)

```twig
{{ craft.superImages.img(asset, { profile: 'responsive', variant: 'md', format: 'webp' }) }}

{{ asset|generateUrl('webp', { variant: 'lg' }) }}
{{ asset|generatePictureTag({ profile: 'responsive', sizes: '100vw' }) }}
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
{{ craft.superImages.url('https://cdn.example.com/media/hero.jpg', { format: 'avif' }) }}
```

Local paths must sit under `sources.local.allowedRoots`. Remote hosts must be listed in `sources.remote.allowedHosts`.

---

## Architecture

### One pipeline

Every generation path (CLI, queue, runtime, Playground, explicit `generate()`) calls the same `GenerationService`.

### Deterministic identity

Derivative paths are derived from a stable identity hash of the generation definition (source identity + profile/variant/format/operations/options/driver). There is **no** `GeneratedImage` database table.

### Delivery contract

| Mode | Twig emits |
|---|---|
| `lazy` (default) | Signed `/actions/super-images/runtime/generate?...` |
| `eager` | Final storage/CDN URL |
| `hybrid` | Storage URL today (same as eager; reserved for profile-level lazy overrides later) |

Normal Twig render never checks storage existence, markers, or remote HEAD.

### Storage markers

For remote adapters, tiny existence markers live under Craft `@storage/super-images/markers` (never webroot). They are not image binaries.

---

## Permissions (CP)

| Permission | Purpose |
|---|---|
| `super-images:view` | Dashboard / encoders overview |
| `super-images:playground` | Playground |
| `super-images:diagnostics` | Diagnostics |
| `super-images:manage-settings` | Settings overview |

---

## Related

- Planning docs: `plan/`
- Example config: `config/super-images.example.php`
