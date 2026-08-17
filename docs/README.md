# Super Images

Image transforms, modern formats, optimization, and CDN delivery for **Craft CMS 5**.

New here? Start with **[Getting started](./getting-started.md)** — install, first `<img>`, storage, and CLI in five steps.

```text
Source → Transform → Encode → Optimize → Store → URL
```

---

## What you can do

| Goal | How |
|---|---|
| Responsive `<img>` / `<picture>` | [Twig](./twig.md) — `craft.superImages.img()` / `picture()` |
| Pre-build all sizes on deploy | [CLI](./cli.md) — `super-images/generate` |
| Store on R2 / S3 / local disk | [Storage](./storage.md) |
| Custom CDN or optimizer | [Extension API](./extension-api.md) + [`examples/`](../examples/README.md) |
| Learn by clicking | [Interactive demo](./demo.md) |

---

## Documentation

### Start here
- **[Getting started](./getting-started.md)** — install, first image, storage, generate
- **[Configuration](./configuration.md)** — every config key
- **[Twig & frontend](./twig.md)** — `url`, `img`, `picture`, `srcset`, operations

### Operations
- **[CLI & queue](./cli.md)** — generate, doctor, cleanup, status
- **[Storage & naming](./storage.md)** — adapters, paths, markers, R2/Spaces
- **[Encoders & optimizers](./encoders-optimizers.md)** — jpegoptim, cwebp, job vs runtime
- **[Delivery](./delivery.md)** — generate before page load vs signed runtime URLs
- **[Policies](./policies.md)** — safety, fallback, auto-cleanup rules

### Control Panel
- **[Control Panel](./control-panel.md)** — dashboard, playground, settings
- **[Diagnostics](./diagnostics.md)** — doctor checks, cleanup philosophy

### Extend
- **[Extension API](./extension-api.md)** — events, contracts, registration
- **[Examples](../examples/README.md)** — copy-paste storage, encoder, optimizer, operation classes

---

## Install (short)

```bash
composer require amici/craft-super-images
php craft plugin/install super-images
cp vendor/amici/craft-super-images/config/super-images.example.php config/super-images.php
php craft super-images/doctor
```

Optional demo: `cp -R vendor/amici/craft-super-images/demo templates/super-images` → visit `/super-images`.

---

## Architecture (30 seconds)

- **One pipeline** — CLI, queue, Twig, and CP all call `GenerationService`.
- **Deterministic paths** — each transform gets a hash; changing ops creates a new file, not a stale cache.
- **No DB table** — existence is the file (+ tiny local markers for remote storage).
- **Twig variable only** — use `craft.superImages.*`; there are no Twig filters.

---

## Packages in this repo

| Path | Purpose |
|---|---|
| `config/super-images.example.php` | Annotated project config |
| `demo/` | Interactive Twig lab (copy to `templates/super-images/`) |
| `examples/` | PHP starter classes for third-party extensions |
| `docs/` | Product documentation (this folder) |
