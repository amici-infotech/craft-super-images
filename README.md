# Super Images

High-performance image processing for **Craft CMS 5**.

Transforms, format conversion (JPEG / PNG / WebP / AVIF), optimization, local & S3-compatible storage, and deterministic derivative delivery — one engine for Twig, CLI, queue, and runtime generation.

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
php craft plugin/install super-images
cp vendor/amici/craft-super-images/config/super-images.example.php config/super-images.php
```

### Interactive demo (optional)

```bash
cp -R vendor/amici/craft-super-images/demo templates/super-images
```

Open `/super-images` for live Twig examples (CDN-first — no Asset ID required).

## Quick Twig

```twig
{{ craft.superImages.img(asset, { profile: 'responsive', variant: 'md', format: 'webp' }) }}
{{ craft.superImages.picture(asset, { profile: 'responsive', formats: ['webp', 'jpg'], sizes: '100vw' }) }}
```

## Documentation

Start here: **[docs/README.md](docs/README.md)**

| Topic | Doc |
|---|---|
| Interactive demo | [docs/demo.md](docs/demo.md) |
| Configuration | [docs/configuration.md](docs/configuration.md) |
| Twig API & operations | [docs/twig.md](docs/twig.md) |
| Storage & path naming | [docs/storage.md](docs/storage.md) |
| Control Panel | [docs/control-panel.md](docs/control-panel.md) |
| CLI | [docs/cli.md](docs/cli.md) |
| Encoders / optimizers | [docs/encoders-optimizers.md](docs/encoders-optimizers.md) |

Example config: `config/super-images.example.php`

## License

Proprietary — Amici Infotech
