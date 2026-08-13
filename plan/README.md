# Super Images

**Super Images** is a high-performance image processing, transformation, encoding, optimization, storage, and delivery plugin for **Craft CMS 5**.

The plugin is designed to be more than a replacement for Craft's basic image transforms. It is intended to provide a complete image infrastructure layer for Craft projects, including:

- Multiple source types: Craft Assets, local paths (`/images/abc.png`), and allow-listed CDN/remote URLs
- Multiple image formats: JPEG, PNG, WebP, AVIF, and future formats
- High-performance image processing
- Native/external encoders and optimizers
- Responsive image generation
- Eager generation through CLI and queue
- Automatic queue generation on Asset upload/replace
- Lazy/runtime generation through signed URLs
- Local and remote storage
- S3-compatible storage including Amazon S3, DigitalOcean Spaces, Cloudflare R2, and custom adapters
- Private existence markers under Craft `storage/` for CDN/remote existence checks
- Rich `config/super-images.php` for profiles, storage credentials (via env), paths, URLs, allow-lists, and plugin settings
- Configuration at General, Volume, Folder, and Asset Field levels
- Reusable Profiles and Variants
- Twig helpers for URLs, `<img>`, and `<picture>`
- Control Panel configuration
- Image Playground with visual comparisons
- Extensible drivers, encoders, optimizers, operations, storage adapters, and events
- Strong emphasis on low database usage and low frontend overhead

---

# Important AI Agent Instructions

This document is the top-level architectural contract for Super Images.

The implementation agent **must read this README before implementing any phase**.

## Architectural stability

The decisions in this document are intentional.

Do **not** repeatedly redesign the architecture while implementing individual features.

Only change an established architectural decision when:

1. The current decision is demonstrably technically incorrect;
2. Craft CMS 5 makes the decision impossible or fundamentally incompatible;
3. A significant performance/security problem is discovered;
4. A substantially better architecture solves an important limitation without introducing equivalent complexity.

Minor improvements, naming preferences, or alternative implementation styles are **not sufficient reasons** to redesign an established decision.

If a significant architectural change appears necessary:

1. Stop before implementing the change.
2. Explain the problem.
3. Explain the proposed replacement.
4. Explain the migration/impact.
5. Obtain approval before changing the architecture.

Prefer consistency and incremental improvement over architectural churn.

---

# Core Architectural Decisions

These decisions are considered fixed unless there is a significant technical reason to change them.

## 1. Multiple image sources are first-class

Super Images does **not** only process Craft Assets.

Supported source types:

```text
1. Craft Asset
2. Local path / local URL path
   example: /images/abc.png
3. Remote / CDN URL
   example: https://cdn.example.com/media/hero.jpg
```

Craft Assets remain the native Craft integration and the primary CMS workflow.

Local paths and remote/CDN URLs must go through the same processing pipeline:

```text
Source
  ↓
Source Resolver
  ↓
Generation Service
  ↓
Encode / Optimize / Store
```

Rules:

- originals are never mutated;
- derivatives are generated from the resolved source;
- local paths must resolve only inside configured allow-listed roots;
- remote URLs must resolve only against configured allow-listed hosts/patterns;
- Twig/PHP/CLI/runtime must all be able to consume these source types through the same services.

---

## 2. No GeneratedImage database table

Super Images will **not** create one database record per generated image.

Generated derivative identity and paths are deterministic.

The plugin should be able to calculate:

```text
Source identity
+
Profile
+
Variant
+
Format
+
Processing configuration
=
Deterministic derivative identity
```

Source identity includes Craft Asset ID/version where applicable, or a stable hash/normalized identity for local-path and remote-URL sources.

This avoids a large database table containing potentially millions of generated-image records.

---

## 3. Remote storage does not require a local image mirror

If the configured storage is:

- Amazon S3
- DigitalOcean Spaces
- Cloudflare R2
- another S3-compatible service
- a custom remote adapter

Super Images writes the generated derivative bytes directly to that storage.

It does **not** keep a second full local copy of the image under the webroot just to prove the derivative exists.

### Existence markers are allowed in Craft `storage/`

For CDN/remote storage, Super Images **may** write small local **existence marker** files under Craft’s private storage directory, for example:

```text
storage/super-images/markers/...
```

These markers:

- are tiny placeholder/metadata files, not image binaries;
- live in the Craft `storage/` folder, **never** in the public web folder;
- help CLI/queue/runtime decide whether a remote derivative already exists without a remote HEAD on every check;
- must never be web-accessible;
- must never be treated as the public delivery file.

Temporary processing files are still allowed when drivers/tools need a filesystem path, and must be removed after processing.

---

## 4. Frontend rendering must be extremely lightweight

Normal frontend rendering should not:

- query a generated-image database table;
- perform filesystem existence checks of derivatives;
- read existence markers;
- perform remote storage HEAD requests;
- process images;
- encode images;
- optimize images;
- load image contents.

The normal flow should be approximately:

```text
Source (Asset / local path / allow-listed URL)
    ↓
Resolve lightweight configuration
    ↓
Calculate deterministic derivative URL
    ↓
Render HTML
```

Existence markers and remote exists checks belong to generation/orchestration paths only, not Twig HTML rendering.

This is a core performance requirement.

---

## 5. Automatic queue generation on Asset changes

When a Craft Asset is uploaded or its file is replaced/changed, Super Images must be able to enqueue generation automatically based on configured profiles.

```text
Asset uploaded / file replaced
        ↓
Resolve applicable config (volume/folder/field/general)
        ↓
Build Generation Manifest
        ↓
Enqueue Craft queue jobs
        ↓
Generation Service
```

This is a first-class product behavior, not an afterthought.

It must be:

- driven by `config/super-images.php` / CP settings;
- configurable (enable/disable globally and per volume/field);
- asynchronous via Craft queue by default (no heavy encode during the upload request);
- safe for bulk imports via an opt-out/disable switch.

---

# Configuration Model

Configuration is hierarchical and intentionally rich.

`config/super-images.php` is a first-class home for many settings, including but not limited to:

- profiles / variants / formats / defaults
- driver / encoder / optimizer preferences
- local path allow-lists
- remote/CDN URL allow-lists
- automatic queue generation settings
- storage adapter selection
- local derivative path + public URL
- S3 / Spaces / R2 credentials and endpoints (via env vars)
- CDN/base URLs
- runtime signing secrets and resource limits
- existence-marker settings
- cleanup/retention settings
- plugin feature toggles

The fixed precedence model for Asset-scoped overrides is:

```text
General
   ↓
Volume
   ↓
Folder
   ↓
Asset Field
```

The most specific applicable configuration wins.

Local-path and remote-URL sources use general + explicit source/profile options rather than Volume/Folder/Field scopes unless a calling context provides field context.

The same configuration model must be usable from:

- `config/super-images.php`
- Control Panel
- Twig
- CLI
- Queue jobs
- Runtime generation
- Playground

There must be **one central Configuration Resolver**.

No subsystem should invent its own configuration resolution rules.

---

# Profiles and Variants

Super Images uses two important concepts.

## Profile

A Profile is a reusable image-generation definition.

Example:

```php
'profiles' => [

    'responsive' => [

        'variants' => [
            'sm' => ['width' => 576],
            'md' => ['width' => 768],
            'lg' => ['width' => 992],
            'xl' => ['width' => 1280],
            '2xl' => ['width' => 1600],
        ],

        'defaults' => [
            'mode' => 'crop',
            'position' => '100% 100%',
            'jpegQuality' => 80,
        ],

        'formats' => [
            'jpg',
            'webp',
            'avif',
        ],
    ],

];
```

A Profile is reusable across multiple Volumes, Folders, and Asset Fields.

## Variant

A Variant is one concrete derivative definition.

Example:

```php
'md' => [
    'width' => 768,
]
```

A Profile may contain many Variants.

The generation system expands:

```text
Profile
    ↓
Variants × Formats
    ↓
Generation Manifest
```

---

# Generation Manifest

The Generation Manifest is the central representation of what derivatives should exist.

For:

```text
Profile: responsive

Variants:
576
768
992
1280
1600

Formats:
jpg
webp
avif
```

the manifest represents:

```text
576.jpg
576.webp
576.avif

768.jpg
768.webp
768.avif

992.jpg
992.webp
992.avif

1280.jpg
1280.webp
1280.avif

1600.jpg
1600.webp
1600.avif
```

The same manifest concept must be usable by:

- CLI generation
- Queue generation
- Runtime generation
- Playground
- Diagnostics
- future automation

---

# Eager vs Lazy Generation

Super Images supports both.

## Eager generation

Images are generated before they are requested.

Examples:

```bash
php craft super-images/generate
```

and, by default when configured, through Craft Queue automatically after an Asset is uploaded or its file is replaced.

## Lazy generation

Images are generated when requested.

Example:

```twig
{{ asset|generateUrl('webp') }}
```

can produce a signed runtime URL.

If the derivative does not exist, the runtime endpoint generates it.

After generation, the browser should use the resulting storage/CDN URL whenever possible.

Both eager and lazy generation must use the **same underlying Generation Service**.

Do not implement separate image-processing pipelines for CLI and runtime generation.

---

# Security

Security is a core product requirement. See also `security.md`.

## Runtime generation

Runtime generation must never expose an unrestricted image-processing endpoint.

Runtime transformation URLs must be signed.

The signature must cover security-sensitive information such as:

- source identity (Asset / local path identity / remote URL identity)
- transformation parameters
- format
- profile/recipe
- quality
- relevant processing options

Runtime requests must enforce resource limits such as:

- maximum width
- maximum height
- maximum pixel count
- maximum input size
- permitted formats
- permitted operations
- processing complexity

## Source restrictions

Never accept arbitrary filesystem paths or arbitrary remote URLs from untrusted input.

Local paths must be constrained to configured allow-listed roots.

Remote/CDN fetches must be constrained to configured allow-listed hosts and protected against SSRF.

Never construct unsafe shell commands from runtime input.

## Secrets and storage

- storage credentials belong in environment variables;
- existence markers stay under private Craft `storage/`, never webroot;
- credentials/secrets never enter generation identity, logs, Twig, or public URLs.

---

# Concurrency

If many requests ask for the same missing derivative at the same time, Super Images must avoid generating the same image repeatedly.

Example:

```text
100 requests
      ↓
same derivative
      ↓
1 generation
      ↓
100 requests use same result
```

A suitable locking strategy must be implemented for lazy generation.

---

# Image Processing Architecture

Image processing is driver-based.

The core must not be tightly coupled to one image library.

Initial drivers:

1. **Libvips** — preferred for performance
2. **ImageMagick/Imagick** — broad compatibility
3. **GD** — fallback

The preferred implementation for high-performance environments should be Libvips where available.

---

# Encoding Architecture

Encoding is separate from image processing.

Initial formats:

- JPEG
- PNG
- WebP
- AVIF

Potential future formats can be added through the encoder architecture.

Candidate native/external tools include:

### JPEG

- libjpeg-turbo
- mozjpeg
- jpegoptim

### PNG

- optipng
- oxipng
- pngquant

### WebP

- cwebp

### AVIF

- avifenc
- libavif
- Libvips native AVIF support

The plugin should detect available tools rather than assuming they exist.

---

# Optimization Architecture

Optimization is a separate stage from encoding.

General pipeline:

```text
Source Asset
    ↓
Decode
    ↓
Operations
    ↓
Encode
    ↓
Optimize
    ↓
Validate
    ↓
Storage
```

Optimizers are format-aware.

For example:

```text
JPEG → jpegoptim
PNG  → oxipng / optipng / pngquant
WebP → cwebp / native optimization
AVIF → avifenc / libavif configuration
```

The user should be able to configure which optimizer is preferred.

---

# Operations

Operations represent image modifications.

Initial operations include:

## Geometry

- Resize
- Crop
- Fit
- Fill
- Scale
- Rotate
- Flip

## Color

- Brightness
- Contrast
- Saturation
- Grayscale
- Sepia
- Invert
- Gamma where supported
- Colorize where supported

## Effects

- Sharpen
- Blur
- Gaussian blur where supported

## Composition

- Watermark
- Image overlay
- Background
- Padding
- Border
- Text overlay where supported

The operation architecture must remain extensible.

Third-party plugins should be able to register custom operations.

---

# Storage Architecture

Storage is adapter-based.

Initial storage support:

- Local
- Amazon S3
- DigitalOcean Spaces
- Cloudflare R2
- Generic S3-compatible providers

DigitalOcean Spaces and Cloudflare R2 should be supported through the S3-compatible storage architecture rather than forcing unnecessary provider-specific implementations.

Custom storage adapters must be supported.

---

# Twig API

The first-class Twig API includes Craft Assets **and** non-Asset sources:

```twig
{{ asset|generateUrl('webp') }}
```

```twig
{{ asset|generateImgTag('webp') }}
```

```twig
{{ asset|generatePictureTag(['jpg', 'webp']) }}
```

```twig
{{ '/images/abc.png'|generateUrl('webp') }}
```

```twig
{{ 'https://cdn.example.com/media/hero.jpg'|generatePictureTag() }}
```

The format/profile can be omitted where configuration provides a sensible default:

```twig
{{ asset|generateUrl() }}
```

```twig
{{ asset|generatePictureTag() }}
```

Runtime options should be supported:

```twig
{{ asset|generateUrl('webp', {
    width: 1200,
    quality: 75,
}) }}
```

Local-path and remote-URL sources are subject to allow-lists and the same resource limits as runtime generation.

The exact internal syntax can evolve, but the conceptual API must remain simple.

---

# Responsive Images

A Profile containing multiple widths should be capable of producing responsive image URLs.

Example:

```text
576w
768w
992w
1280w
1600w
```

`generatePictureTag()` should be capable of producing:

```html
<picture>
    <source type="image/avif" ...>
    <source type="image/webp" ...>
    <img ...>
</picture>
```

Format ordering should normally prefer:

```text
AVIF
WebP
JPEG/PNG fallback
```

unless explicitly configured otherwise.

---

# Control Panel

The Control Panel should eventually expose:

```text
Super Images
├── Dashboard
├── Profiles
├── Storage
├── Encoders
├── Optimizers
├── Volumes
├── Folders
├── Asset Fields
└── Playground
```

The Control Panel is not allowed to create a separate configuration model.

It must operate on the same configuration concepts used by the config file and runtime services.

---

# Playground

The Playground is a major product feature.

Users should be able to:

1. Select a Craft Asset.
2. Select a Profile.
3. Select a Variant.
4. Select an output format.
5. Override transformation settings.
6. Preview the result.
7. Compare original and generated dimensions.
8. Compare original and generated file sizes.
9. See percentage size reduction.
10. See the generated URL.
11. See frontend code examples.

Example:

```text
Original
1920 × 1080
JPEG
2.84 MB

Generated
1200 × 675
WebP
184 KB

93.5% smaller
```

Playground previews should use temporary storage and should not pollute permanent production derivative storage.

---

# CLI

The CLI should eventually support commands conceptually equivalent to:

```bash
php craft super-images/generate
```

```bash
php craft super-images/generate --asset=123
```

```bash
php craft super-images/generate --volume=images
```

```bash
php craft super-images/generate --profile=responsive
```

```bash
php craft super-images/generate --dry-run
```

```bash
php craft super-images/config
```

```bash
php craft super-images/config --asset=123
```

```bash
php craft super-images/status
```

```bash
php craft super-images/cleanup
```

The exact command naming may follow Craft conventions, but these capabilities are required.

---

# Queue

Large-scale generation must use Craft's queue.

Generation jobs must be:

- idempotent
- retryable
- reasonably batched
- safe for large Asset libraries

Avoid generating millions of unnecessarily tiny queue records when batching can reduce overhead.

---

# Performance Requirements

Performance is a core feature.

## Normal frontend request

The target is:

```text
Generated-image DB queries: 0
Filesystem existence checks: 0
Remote storage HEAD requests: 0
Image processing: 0
Encoding: 0
Optimization: 0
```

Normal rendering should primarily:

```text
Asset
 ↓
lightweight configuration resolution
 ↓
deterministic URL
 ↓
HTML
```

## Configuration

Configuration should be normalized and cached.

Do not parse and merge large configuration structures repeatedly during a single request.

## Large Asset libraries

CLI and queue generation must process Assets in batches.

Do not load thousands/millions of Assets into memory at once.

---

# Deterministic Derivative Identity

Generated derivative identity must be deterministic.

It should account for the relevant inputs, including:

- Asset identity
- source/version information
- Profile
- Variant
- format
- transformation configuration
- quality/encoding configuration
- plugin processing schema/version

If the processing definition changes, the derivative identity should change.

This allows old derivatives to become obsolete without requiring a large database migration.

---

# Cleanup

Cleanup must be conservative.

The plugin should eventually support:

```bash
php craft super-images/cleanup
```

and:

```bash
php craft super-images/cleanup --dry-run
```

Cleanup may remove:

- obsolete derivatives
- derivatives from deleted Assets
- derivatives generated by obsolete configurations
- abandoned runtime derivatives

However, the plugin must not delete a file merely because it cannot immediately prove that it is unused.

Use retention periods and dry-run support.

---

# Extension API

Super Images is intended to be an extensible platform.

Third-party plugins should be able to register:

- Image Drivers
- Encoders
- Optimizers
- Storage Adapters
- Operations
- Recipes/providers where appropriate

Provide clear registries and Craft/Yii-style events.

Potential events include:

```text
beforeProcess
afterProcess

beforeEncode
afterEncode

beforeOptimize
afterOptimize

beforeStore
afterStore

beforeGenerate
afterGenerate
```

The exact event API should be finalized during implementation, but extensions must not need to modify Super Images core files.

---

# Three-Phase Development Plan

The project is divided into three major phases.

The smaller implementation documents inside each phase are implementation modules, not separate products.

---

## Phase 1 — Core Engine

### Objective

Build the complete image infrastructure underneath the plugin.

### Includes

- Plugin architecture
- Core contracts
- Configuration system
- Configuration resolver
- Rich `config/super-images.php` surface (storage, credentials via env, paths, URLs, allow-lists, auto-queue, limits)
- Profiles
- Variants
- Configuration precedence
- Source Resolver for Craft Assets, local paths, and allow-listed remote/CDN URLs
- Image drivers
- Libvips
- Imagick
- GD
- Image operations
- Encoders
- JPEG
- PNG
- WebP
- AVIF
- Optimizers
- cwebp
- jpegoptim
- optipng
- oxipng
- pngquant
- avifenc where available
- Storage abstraction
- Local storage
- S3-compatible storage
- DigitalOcean Spaces
- Cloudflare R2
- Custom storage adapter foundation
- Private existence markers under Craft `storage/`
- Deterministic derivative identity

### Definition of Done

At the end of Phase 1, the plugin must be able to take:

```text
Source (Craft Asset | local path | allow-listed remote URL)
+
Profile
+
Variant
+
Format
```

and produce a correctly processed, encoded, optimized derivative in the configured storage.

---

## Phase 2 — Generation & Delivery

### Objective

Make the engine useful to developers and production websites.

### Includes

- Generation Manifest
- CLI
- Dry-run generation
- Queue generation
- Automatic queue generation on Asset upload/replace
- Eager generation
- Runtime/lazy generation
- Signed Action URLs
- Resource limits
- Source allow-lists for local paths and remote URLs
- Concurrency protection
- Deterministic URL generation
- Twig API for Assets, local paths, and allow-listed URLs
- `generateUrl`
- `generateImgTag`
- `generatePictureTag`
- Responsive `srcset`
- `<picture>`
- Runtime custom transformations
- CDN/direct storage URLs
- Existence-marker-aware generation skips (not on Twig render)
- Lightweight frontend integration

### Definition of Done

A developer should be able to configure:

```php
'fields' => [
    'heroImage' => [
        'profiles' => ['responsive'],
    ],
],
```

and use:

```twig
{{ asset|generatePictureTag() }}
```

without manually generating every image.

They should also be able to run:

```bash
php craft super-images/generate
```

to eagerly generate configured derivatives.

---

## Phase 3 — Product & Ecosystem

### Objective

Turn the engine into a polished, production-ready Craft CMS plugin.

### Includes

- Control Panel
- Dashboard
- Settings UI
- Profile management
- Storage UI
- Encoder/optimizer diagnostics
- Volume configuration
- Folder configuration
- Field configuration
- Inheritance visualization
- Playground
- Original vs generated comparison
- File-size comparison
- Format comparison
- Code generation
- Extension API
- Events
- Custom driver/encoder/optimizer/operation/storage registration
- Cleanup
- Diagnostics
- Status command
- Performance hardening
- Documentation
- Comprehensive tests
- Production QA

### Definition of Done

Super Images should be suitable for use as a serious Craft CMS image infrastructure/product rather than merely an image-transform helper.

---

# Implementation Order

The AI agent must implement phases sequentially.

```text
Phase 1
  ↓
Phase 1 tests
  ↓
Phase 2
  ↓
Phase 2 tests
  ↓
Phase 3
  ↓
Final integration tests
```

Do not begin Phase 3 UI work by creating parallel versions of services that belong in Phase 1 or Phase 2.

---

# Project Structure

The implementation documentation should follow this organization:

```text
plan/

├── README.md
├── security.md
│
├── phase-1-core-engine/
│   ├── README.md
│   ├── architecture.md
│   ├── configuration.md
│   ├── drivers-encoders-optimizers.md
│   ├── operations.md
│   └── storage.md
│
├── phase-2-generation-delivery/
│   ├── README.md
│   ├── manifest.md
│   ├── cli-queue.md
│   ├── runtime-generation.md
│   └── twig-frontend.md
│
└── phase-3-product-ecosystem/
    ├── README.md
    ├── control-panel.md
    ├── playground.md
    ├── extension-api.md
    ├── cleanup-diagnostics.md
    └── final-qa.md
```

The exact number of supporting documents may change if implementation reveals that two documents should be combined, but the three-phase architecture should remain stable.

---

# AI Agent Working Rules

## Before coding

1. Read this README.
2. Read the README for the current phase.
3. Read the relevant implementation module.
4. Inspect the existing repository.
5. Understand existing Craft CMS 5 conventions before implementing.

## While coding

1. Reuse established services and contracts.
2. Do not duplicate configuration resolution.
3. Do not duplicate image-processing pipelines.
4. Do not bypass storage adapters.
5. Do not add generated-image DB records.
6. Do not add frontend filesystem/storage existence checks.
7. Do not create persistent local **image** mirrors for remote storage.
8. Existence markers, if used, belong only under private Craft `storage/`, never webroot.
9. Local-path and remote-URL sources must enforce allow-lists and SSRF protections.
10. Keep classes focused.
11. Add tests with significant functionality.
12. Prefer explicit, maintainable code over clever abstractions.

## Architectural changes

Do not change an established decision because of:

- personal coding preference
- a different naming convention
- a slightly shorter implementation
- a minor performance optimization
- a different folder structure
- speculative future requirements

A change is justified when the existing decision is:

- technically incorrect
- incompatible with Craft CMS
- insecure
- demonstrably too slow
- unable to support a required feature
- substantially inferior to a clearly better design

When that happens, document the reason before changing it.

---

# Current Product Vision

Super Images should ultimately feel like this:

```text
                         SUPER IMAGES
                              │
              ┌───────────────┼───────────────┐
              │               │               │
         Configuration     Processing       Storage
              │               │               │
        Profiles/Variants   Drivers        Local/S3/R2
        Volume/Folder/      Operations     Spaces/Custom
        Field overrides     Encoders
                             Optimizers
              │               │               │
              └───────────────┼───────────────┘
                              │
                       Generation Engine
                              │
                 ┌────────────┴────────────┐
                 │                         │
             Eager/CLI                 Lazy/URL
                 │                         │
              Queue                    Runtime
                 │                         │
                 └────────────┬────────────┘
                              │
                         Twig API
                              │
                  ┌───────────┴───────────┐
                  │                       │
              generateUrl          generatePictureTag
                  │                       │
                  └───────────┬───────────┘
                              │
                         CDN / Browser
```

The plugin's most important qualities are:

**Fast.**

**Query-sparing.**

**Deterministic.**

**Extensible.**

**Storage-independent.**

**Craft-native.**

**Easy for developers to use.**

**Powerful enough for large production sites.**

---

# Final Principle

Do not optimize for the number of features.

Optimize for a strong core architecture that makes additional image features inexpensive to add.

A new operation, encoder, optimizer, storage adapter, or frontend feature should fit into the existing pipeline rather than requiring another architectural rewrite.

The objective is to build the image infrastructure once and then continuously expand its capabilities without compromising its performance or simplicity.
