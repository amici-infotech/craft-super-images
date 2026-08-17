# Changelog

All notable changes to **Super Images** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [5.0.0] — 2026-08-17

Initial public release for **Craft CMS 5**.

### Added

#### Core pipeline
- Unified `GenerationService` — one pipeline for Twig, Control Panel, CLI, queue, and signed runtime URLs
- Deterministic derivative paths and generation identity hashing (config/ops changes produce new files, not stale cache)
- File-based existence tracking — no `GeneratedImage` database table; derivatives live on the configured storage adapter
- Per-request memoization for existence checks and generation planning

#### Image drivers
- GD, Imagick, and Libvips (`jcupitt/vips`) drivers with automatic `auto` fallback (libvips → imagick → gd)
- Native encode for JPEG, PNG, WebP, and AVIF
- Geometry operations: resize, crop, fit, fill, scale, rotate, flip
- Color operations: grayscale, brightness, contrast, saturation, sepia, invert
- Effects: blur, sharpen
- Composition: watermark, text overlay, image overlay, padding, border, background

#### Formats & optimization
- Configurable encoders (quality, metadata stripping, custom CLI argument maps)
- External optimizers: `jpegoptim`, `oxipng`, `optipng`, `pngquant`, `cwebp`, `avifenc`
- `optimizeType` setting — defer same-format post-optimizers to the Craft queue (`job`) or run inline (`runtime`)
- PNG→WebP/AVIF via `cwebp` / `avifenc` with native fallback (never ships PNG renamed as `.webp`)
- `OptimizeDerivativeJob` — reads stored object (local or remote), optimizes, overwrites same path/URL

#### Storage & delivery
- Local filesystem adapter
- S3-compatible adapter for AWS S3, DigitalOcean Spaces, and Cloudflare R2
- Configurable storage adapters via extension events
- Public URL delivery and signed runtime generation URLs
- Existence markers (`@storage/super-images/markers`) for fast remote cache hits without network HEAD requests
- Per-asset derivative index for remote cleanup and orphan detection
- Long-lived `Cache-Control` on R2/S3 uploads (`public, max-age=31536000, immutable`)

#### Twig & frontend
- `craft.superImages` variable API — `url`, `img`, `picture`, `srcset`, `sources`, `exists`, `isEnabled`
- Profile/variant/format resolution from `config/super-images.php`
- Inline `operations` arrays for ad-hoc transforms
- `generateBeforePageLoad` — generate during page request or defer via signed runtime URLs

#### CLI
- `super-images/status` — resolved config snapshot
- `super-images/config` — dump effective configuration (optional `--asset` manifest sample)
- `super-images/generate` — eager or queued generation by asset, volume, profile, variant, format
- `super-images/doctor` — PASS/WARN/FAIL diagnostics (human or `--json=1`)
- `super-images/cleanup` — aged, orphaned, per-asset, or full purge (`--all=1` clears markers + index)

#### Control Panel
- Dashboard, settings, encoders overview, playground, and diagnostics
- Permission-gated CP section

#### Auto-generate & policies
- Auto-generate on upload, replace, and focal-point change (inline or queued)
- Volume-level overrides
- Safety policies, fallbacks, and automatic cleanup when assets are deleted or replaced
- Configurable retention for generated and preview artifacts

#### Extension API
- Register custom drivers, encoders, optimizers, operations, and storage adapters via Yii events
- Runnable reference classes in `examples/` — storage adapter, WebP encoder, JPEG optimizer, tint operation, and sample plugin wiring
- Generation lifecycle events (`BEFORE_GENERATE`, `AFTER_GENERATE`, `BEFORE_ENCODE`, `AFTER_ENCODE`)

#### Documentation & demo
- Full docs under `docs/` — getting started, configuration, Twig, storage, CLI, encoders/optimizers, delivery, policies, extension API
- Interactive Twig demo (`demo/`) — copy to `templates/super-images` and visit `/super-images`
- Annotated example config: `config/super-images.example.php`

### Requirements

- Craft CMS ^5.0
- PHP ^8.2
- At least one image driver: GD, Imagick, or Libvips
- Optional: `aws/aws-sdk-php` for S3 / Spaces / R2
- Optional: external optimizer binaries on `$PATH`

[5.0.0]: https://github.com/amici-infotech/craft-super-images/releases/tag/5.0.0
