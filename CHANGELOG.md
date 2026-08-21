# Changelog

All notable changes to **Super Images** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [5.1.0] — 2026-08-21

### Fixed
- PHP-FPM / nginx **502** with libvips: isolate image work from the FPM process (prefer native `vips` CLI; fall back to a PHP CLI worker), matching the ExpressionEngine mitigation
- Unavailable explicit `driver` preference now falls through to the next usable driver instead of failing hard
- GD / Imagick availability probes are stricter (real usability in the current SAPI, not “extension mentioned”)
- Playground result meta matches the demo-style pills (dimensions / size / duration) with an open-in-new-tab link instead of a raw storage path
- Dashboard: `1 profile` grammar; Settings hides “View install hints →” when all required optimizer binaries are available

### Added
- `LibvipsCliBridge` + `bin/libvips-worker.php` for safe libvips under FPM
- Isolated pipeline path in `GenerationService` (native encode under isolation; Imagick/GD fallback on native failure)
- Docs: full [Drivers](./docs/drivers.md) Ubuntu (+ Herd) install guides and FAQs; expanded [Diagnostics](./docs/diagnostics.md), encoder tradeoffs, getting-started / CP cross-links
- Diagnostics: precise “why unavailable” details + install suggestions; FFI check; Imagick + Libvips dual-driver check; fail when no driver is usable; warn when pinned `driver` is unusable
- Example / project config comments for encoder `effort` / `method` / progressive tradeoffs
- `.env` overrides documented for `SUPER_IMAGES_VIPS_BINARY` / `SUPER_IMAGES_PHP_BINARY` / `SUPER_IMAGES_VIPS_ISOLATE`

### Changed
- Dashboard “Doctor” panel renamed to **System Health**; CLI doctor header prints **Diagnostics**
- Driver manager logs and falls back when a requested driver is missing

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
- Existence markers for remote adapters (cheap local checks before remote HEAD)
- Configurable derivative path templates / naming conventions

#### Twig & frontend
- `craft.superImages.url()`, `img()`, `picture()`, `srcset()`, `tryGenerate()`, `generate()`
- Profile × variant × format responsive delivery

#### Control Panel
- Dashboard, Playground, Encoders & Optimizers, Diagnostics, Settings
- Asset index / asset detail actions to generate or clear Super Images derivatives

#### CLI
- `super-images/doctor`, `status`, `config`, `generate`, `cleanup`

#### Docs & demo
- Interactive Twig demo (`demo/`) — copy to `templates/super-images` and visit `/super-images`
- Annotated example config: `config/super-images.example.php`

### Requirements

- Craft CMS ^5.0
- PHP ^8.2
- At least one image driver: GD, Imagick, or Libvips
- Optional: `aws/aws-sdk-php` for S3 / Spaces / R2
- Optional: external optimizer binaries on `$PATH`

[5.1.0]: https://github.com/amici-infotech/craft-super-images/releases/tag/5.1.0
[5.0.0]: https://github.com/amici-infotech/craft-super-images/releases/tag/5.0.0
