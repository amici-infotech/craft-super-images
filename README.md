# Super Images

High-performance image processing infrastructure for **Craft CMS 5**.

Transforms, format conversion (JPEG/PNG/WebP/AVIF), optimization, local & S3-compatible storage, and deterministic derivative delivery — built as a reusable engine for Twig, CLI, queue, and runtime generation.

## Requirements

- Craft CMS 5.x
- PHP 8.2+
- At least one image driver: GD, Imagick, or Libvips (`jcupitt/vips`)

Optional:

- `aws/aws-sdk-php` for S3 / Spaces / R2
- External optimizers: `jpegoptim`, `oxipng`, `optipng`, `pngquant`, `cwebp`, `avifenc`

## Installation

```bash
composer require amici/craft-super-images
```

Then install the plugin in Craft.

## Configuration

Copy and adapt:

```text
config/super-images.php
```

See `config/super-images.example.php` in this package and `plan/` for architecture.

## Phase status

- **Phase 1 (Core Engine)** — in progress / implemented in `src/`
- Phase 2 — Generation & Delivery (CLI, queue, Twig, runtime)
- Phase 3 — Control Panel, Playground, cleanup, extensions

## License

Proprietary — Amici Infotech
