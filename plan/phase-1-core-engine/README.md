# Phase 1 — Core Engine

Phase 1 builds the **core image infrastructure** of Super Images.

This phase is intentionally focused on the engine underneath the plugin. It must be usable by later phases without requiring those phases to create alternate implementations of core services.

At the end of Phase 1, Super Images must be able to take a Craft Asset, resolve a configured Profile/Variant, process the image, encode it, optimize it, and store the resulting derivative through the configured storage adapter.

---

# Phase 1 Objective

Build a stable, testable image-processing pipeline:

```text
Craft Asset
    ↓
Configuration Resolver
    ↓
Profile / Variant
    ↓
Generation Definition
    ↓
Image Driver
    ↓
Operations
    ↓
Encoder
    ↓
Optimizer
    ↓
Storage Adapter
    ↓
Generated Derivative
```

The implementation must establish the contracts and services required by Phases 2 and 3.

---

# What Phase 1 Includes

Phase 1 consists of five implementation areas:

```text
phase-1-core-engine/

├── README.md
│
├── architecture.md
├── configuration.md
├── drivers-encoders-optimizers.md
├── operations.md
└── storage.md
```

## 1. Architecture

Defines:

- Plugin bootstrap
- Service boundaries
- Dependency injection
- Core contracts
- Registries
- Exceptions
- Value objects
- Processing context
- Generation identity
- Service lifecycle
- Testing architecture

See:

```text
architecture.md
```

---

## 2. Configuration

Defines:

- `config/super-images.php`
- Configuration schema
- Profiles
- Variants
- Defaults
- Formats
- General configuration
- Volume configuration
- Folder configuration
- Asset Field configuration
- Configuration precedence
- Configuration normalization
- Configuration caching
- Control Panel compatibility

See:

```text
configuration.md
```

---

## 3. Drivers, Encoders & Optimizers

Defines:

- Image driver abstraction
- Libvips
- Imagick
- GD
- Encoder abstraction
- JPEG
- PNG
- WebP
- AVIF
- Optimizer abstraction
- jpegoptim
- cwebp
- oxipng
- optipng
- pngquant
- avifenc where available
- Tool discovery
- Capability detection
- Fallback behavior

See:

```text
drivers-encoders-optimizers.md
```

---

## 4. Operations

Defines the image manipulation layer:

- Resize
- Crop
- Fit
- Fill
- Scale
- Rotate
- Flip
- Sharpen
- Blur
- Brightness
- Contrast
- Saturation
- Grayscale
- Sepia
- Invert
- Gamma where supported
- Colorize where supported
- Background
- Padding
- Border
- Watermark
- Image overlay
- Extensible custom operations

See:

```text
operations.md
```

---

## 5. Storage

Defines:

- Storage abstraction
- Local storage
- S3-compatible storage
- Amazon S3
- DigitalOcean Spaces
- Cloudflare R2
- Custom storage adapters
- Deterministic derivative paths
- Storage URLs
- Temporary processing files
- Remote storage behavior
- Storage capability detection

See:

```text
storage.md
```

---

# Phase 1 Non-Goals

The following are intentionally **not implemented as Phase 1 features**.

They may use Phase 1 services, but Phase 1 must not build separate implementations for them.

## Control Panel UI

No complete Settings UI.

No Playground.

No Dashboard.

No visual configuration editor.

These belong to Phase 3.

---

## Twig API

Do not implement:

```twig
{{ asset|generateUrl() }}
{{ asset|generateImgTag() }}
{{ asset|generatePictureTag() }}
```

in Phase 1.

The engine must be designed so Phase 2 can implement these cleanly.

---

## Runtime Action URLs

Do not build lazy/runtime generation endpoints in Phase 1.

Phase 1 should expose a generation service that Phase 2 can call.

---

## CLI

Phase 1 may provide internal services that a future CLI can call, but the complete CLI command set belongs to Phase 2.

Do not duplicate generation logic inside a future CLI.

---

## Queue

Queue integration belongs to Phase 2.

Phase 1 generation services must remain queue-agnostic.

---

## Responsive Image HTML

No `<picture>` generation.

No `srcset`.

No `sizes`.

These belong to Phase 2.

---

## Cleanup

Derivative cleanup belongs to Phase 3.

Phase 1 only needs deterministic identity/path information that makes cleanup possible later.

---

# Core Design Principle

Phase 1 is the **single source of truth for image generation behavior**.

Later phases must call Phase 1 services.

They must not reimplement processing.

For example:

```text
CLI
 ↓
Generation Service
 ↓
Phase 1 Pipeline
```

Runtime generation:

```text
Action URL
 ↓
Generation Service
 ↓
Phase 1 Pipeline
```

Playground:

```text
Playground
 ↓
Generation Service
 ↓
Phase 1 Pipeline
```

Twig:

```text
Twig
 ↓
URL / Generation Service
 ↓
Phase 1 Pipeline
```

There must be one actual image-processing pipeline.

---

# Required Core Pipeline

The primary pipeline should conceptually be:

```text
Source
  ↓
Source Resolver
  ↓
Processing Context
  ↓
Image Driver
  ↓
Operations
  ↓
Encoder
  ↓
Optimizer
  ↓
Output Validation
  ↓
Storage Adapter
  ↓
Derivative Result
```

The exact internal class names may differ, but the separation of responsibilities must remain.

---

# Source Asset Handling

The engine must work with Craft Assets.

The source layer should be responsible for:

- Resolving the Asset
- Opening the source image
- Reading source metadata where required
- Detecting dimensions
- Detecting source format
- Handling local source files
- Handling Assets stored on remote volumes where supported by Craft

The engine should not assume that every Craft Asset is stored locally.

---

# Processing Context

A processing context should carry the information needed throughout one generation operation.

Conceptually:

```text
ProcessingContext
├── Asset
├── Source
├── Profile
├── Variant
├── Format
├── Operations
├── Encoder options
├── Optimizer options
├── Storage configuration
└── Generation identity
```

Do not pass large associative arrays through every service if a focused value object can express the concept more safely.

---

# Generation Identity

Every generated derivative needs a deterministic identity.

The identity must be based on the relevant generation inputs, including:

- Source Asset identity
- Source/version information where appropriate
- Profile
- Variant
- Format
- Transformation settings
- Encoding settings
- Optimization settings where they affect output
- Processing schema/version

The exact hashing/path algorithm will be defined in `architecture.md`.

The important requirement is:

```text
same input
    +
same configuration
    =
same derivative identity
```

and:

```text
meaningful configuration change
    =
new derivative identity
```

This allows future versions of Super Images to invalidate old derivatives without maintaining a GeneratedImage database.

---

# Configuration Resolution

Phase 1 must implement the central Configuration Resolver.

Configuration precedence is:

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

The resolver must be usable by every later subsystem.

There must not be separate configuration-merging implementations for:

- CLI
- Runtime
- Twig
- Playground
- Queue
- Control Panel

All must eventually use the same resolver.

---

# Profiles

A Profile is a reusable image-generation definition.

Example:

```php
'profiles' => [

    'responsive' => [

        'variants' => [

            'sm' => [
                'width' => 576,
            ],

            'md' => [
                'width' => 768,
            ],

            'lg' => [
                'width' => 992,
            ],

            'xl' => [
                'width' => 1280,
            ],

            '2xl' => [
                'width' => 1600,
            ],

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

],
```

Profiles must be normalized into a consistent internal representation.

---

# Variants

A Variant represents one concrete derivative definition.

Example:

```php
'md' => [
    'width' => 768,
]
```

Variants must be independent of storage.

They describe what the image should look like, not where it should be stored.

---

# Format Handling

Phase 1 must support at minimum:

```text
JPEG
PNG
WebP
AVIF
```

Format selection must be explicit.

The system should have a format registry so future formats can be introduced without modifying the central generation pipeline.

---

# Image Driver Strategy

Initial drivers:

```text
Libvips
Imagick
GD
```

Preferred order:

```text
Libvips
    ↓
Imagick
    ↓
GD
```

However, selection must be capability-based and configurable rather than blindly assuming a library exists.

Libvips is the preferred driver for production performance where available.

GD exists primarily for compatibility and fallback.

---

# Operations Strategy

Operations must be independent of storage and encoding.

For example:

```text
Resize
Crop
Sharpen
Watermark
```

must operate on the image representation supplied by the selected driver.

Operations should not:

- save files;
- choose storage;
- construct URLs;
- perform optimization;
- know about Craft Asset fields;
- know about Twig;
- know about CLI.

This separation is essential.

---

# Encoder Strategy

Encoding must be independent from storage.

Example:

```text
Processed Image
      ↓
WebP Encoder
      ↓
WebP Output
```

An encoder should be responsible for:

- format selection
- quality
- codec-specific options
- output generation
- encoder capability checks

It should not know where the result will eventually be stored.

---

# Optimizer Strategy

Optimization occurs after encoding.

Example:

```text
Image
 ↓
WebP Encoder
 ↓
WebP output
 ↓
WebP optimization
 ↓
Final output
```

Optimization must be optional.

If an optimizer is unavailable, the plugin should have a clearly defined fallback behavior.

A missing optional optimizer must not necessarily make the entire image engine unusable.

---

# External Tool Philosophy

External tools such as:

```text
cwebp
jpegoptim
oxipng
optipng
pngquant
avifenc
```

are valuable, but they cannot be assumed to exist on every Craft installation.

The plugin must:

1. Detect available tools.
2. Detect their versions/capabilities where practical.
3. Allow configuration of preferred tools.
4. Fall back to native library encoding/optimization when possible.
5. Clearly report unavailable tools.
6. Never silently pretend that optimization happened when it did not.

---

# Storage Strategy

Storage is a first-class abstraction.

The generation engine must not write directly to:

```text
local filesystem
S3
R2
Spaces
```

Instead:

```text
Generation Service
      ↓
Storage Adapter
      ↓
Actual storage
```

Initial adapters:

```text
Local
S3-compatible
```

S3-compatible configuration must be sufficient for:

```text
Amazon S3
DigitalOcean Spaces
Cloudflare R2
```

Provider-specific adapters may be added later if a provider requires behavior that cannot be represented cleanly through the generic S3 adapter.

---

# Remote Storage Rule

When remote storage is configured:

```text
Source
 ↓
Temporary processing file if required
 ↓
Process
 ↓
Encode
 ↓
Optimize
 ↓
Remote storage
 ↓
Delete temporary local file
```

Do not do:

```text
Process
 ↓
Local permanent cache
 ↓
Remote storage
```

just to maintain an existence cache.

The storage adapter is responsible for storage operations.

---

# Storage Existence

Phase 1 must provide the abstraction necessary for future existence checks.

However, the normal frontend rendering path must not depend on them.

Later runtime generation may need:

```text
does derivative exist?
```

That decision belongs to Phase 2.

Phase 1 only provides the storage capability.

---

# Storage URLs

Storage adapters should be able to return a usable public URL when the storage supports it.

For example:

```text
https://cdn.example.com/images/...
```

or:

```text
https://bucket.s3.amazonaws.com/...
```

The core should not assume that every storage URL is a local filesystem path.

---

# Temporary Files

Some image libraries and external optimization tools require filesystem paths.

Temporary files are allowed.

Requirements:

- use secure temporary locations;
- use deterministic names only when safe/necessary;
- remove temporary files after processing;
- clean up after exceptions;
- never expose temporary paths publicly;
- never treat temporary files as permanent derivatives.

---

# Error Handling

Phase 1 must define meaningful domain exceptions.

Examples:

```text
UnsupportedFormatException
DriverUnavailableException
EncoderUnavailableException
OptimizerUnavailableException
InvalidOperationException
InvalidConfigurationException
StorageException
ProcessingException
```

Errors should provide enough context for CLI diagnostics and future Control Panel diagnostics.

Do not expose sensitive filesystem credentials or internal secrets in exceptions.

---

# Logging

Phase 1 services should use Craft/Yii logging appropriately.

Logging should be useful for:

- driver selection
- encoder selection
- optimizer selection
- storage failures
- processing failures
- configuration errors

Do not log:

- access keys
- secret keys
- signed URLs
- credentials
- full sensitive configuration

Normal successful frontend rendering should not generate noisy logs.

---

# Testing Requirements

Phase 1 must be heavily tested because every later phase depends on it.

At minimum test:

## Configuration

- default configuration
- profile resolution
- variant resolution
- precedence
- overrides
- invalid configuration
- configuration normalization

## Drivers

- Libvips availability
- Imagick availability
- GD fallback
- driver capability checks

## Operations

- resize
- crop
- fit
- fill
- rotation
- flip
- sharpen
- color operations
- watermark
- operation ordering

## Encoders

- JPEG
- PNG
- WebP
- AVIF where environment supports it
- quality settings
- invalid format handling

## Optimizers

- available tool
- unavailable tool
- fallback
- optimization failure

## Storage

- local storage
- S3-compatible storage using a test/mocked adapter
- write
- read/existence capability
- URL generation
- failure handling
- temporary file cleanup

## Generation

- deterministic identity
- same input produces same identity
- changed configuration produces different identity
- complete pipeline execution

---

# Performance Requirements

Phase 1 is not merely about correctness.

Performance must be considered from the beginning.

## Avoid unnecessary work

Do not:

- decode an image twice;
- re-encode unnecessarily;
- run multiple optimizers without reason;
- copy large image buffers unnecessarily;
- load entire unrelated configuration structures repeatedly;
- perform database queries during processing unless genuinely required.

## Memory

Large images can consume significant memory.

Use driver capabilities appropriately.

Avoid retaining multiple full-resolution image representations at the same time where possible.

## Remote storage

Do not download a generated derivative simply to determine that it exists.

The storage adapter should expose the appropriate operation.

---

# Database Philosophy

Phase 1 should add as little database state as possible.

There must be:

```text
NO GeneratedImage table
NO one-row-per-derivative records
NO frontend lookup table
```

Craft's existing Asset/Volume system remains the primary source of asset information.

Configuration may use Craft's standard project-config/settings mechanisms where appropriate, but generated derivative state must not become a database dependency.

---

# Cache Philosophy

Caching is allowed and encouraged where it reduces expensive repeated work.

Appropriate candidates include:

- normalized configuration
- available driver capabilities
- encoder capabilities
- optimizer detection
- storage configuration
- immutable metadata

Do not create a cache that becomes a second source of truth for generated derivatives.

---

# Dependency Philosophy

Prefer mature, well-maintained libraries.

Do not add dependencies merely because they provide a small convenience.

When a dependency is optional, treat it as optional when practical.

External binary tools should be capability-detected.

---

# Craft CMS 5 Compatibility

The plugin targets:

```text
Craft CMS 5
PHP 8.2+
```

Implementation must follow Craft CMS 5 conventions.

Before introducing a Craft-specific integration, inspect the current Craft CMS 5 APIs and conventions rather than assuming older Craft CMS behavior.

Do not implement compatibility layers for old Craft versions unless explicitly required later.

---

# Phase 1 Dependency Graph

The intended dependency order is:

```text
Architecture
    ↓
Configuration
    ↓
Drivers
    ↓
Operations
    ↓
Encoders
    ↓
Optimizers
    ↓
Storage
    ↓
Generation Pipeline
```

In practice, some interfaces can be defined earlier so components can be developed/tested independently.

The final integration must connect them into one pipeline.

---

# Phase 1 Milestones

## Milestone 1 — Foundation

Implement:

- Plugin bootstrap
- DI
- contracts
- value objects
- exceptions
- registries
- base services
- test infrastructure

Definition of done:

The plugin loads cleanly in Craft CMS 5 and has a stable service architecture.

---

## Milestone 2 — Configuration

Implement:

- config file
- profiles
- variants
- defaults
- formats
- hierarchy
- resolver
- normalization
- caching

Definition of done:

Given a Craft Asset, the configuration resolver can produce the effective generation configuration.

---

## Milestone 3 — Image Engine

Implement:

- driver abstraction
- Libvips
- Imagick
- GD
- operation pipeline

Definition of done:

A source image can be loaded and transformed without encoding/storage concerns.

---

## Milestone 4 — Encoding & Optimization

Implement:

- encoder abstraction
- JPEG
- PNG
- WebP
- AVIF
- optimizer abstraction
- external tool detection
- optimizers

Definition of done:

A processed image can become a final optimized output in each supported format where the environment supports it.

---

## Milestone 5 — Storage

Implement:

- storage abstraction
- local adapter
- S3-compatible adapter
- deterministic paths
- URLs
- temporary file handling

Definition of done:

A generated image can be written directly to configured storage without requiring a permanent local mirror.

---

## Milestone 6 — Pipeline Integration

Connect everything:

```text
Asset
 ↓
Config
 ↓
Profile
 ↓
Variant
 ↓
Driver
 ↓
Operations
 ↓
Encoder
 ↓
Optimizer
 ↓
Storage
 ↓
Result
```

Definition of done:

A complete generation can execute successfully from a single application service.

---

# Phase 1 Definition of Done

Phase 1 is complete only when all of the following are true.

## Core

- [ ] Plugin loads under Craft CMS 5.
- [ ] Core services are registered through DI.
- [ ] Contracts are stable.
- [ ] Domain exceptions exist.
- [ ] Registries exist where required.

## Configuration

- [ ] `config/super-images.php` works.
- [ ] Profiles work.
- [ ] Variants work.
- [ ] General configuration works.
- [ ] Volume configuration works.
- [ ] Folder configuration works.
- [ ] Asset Field configuration works.
- [ ] Precedence works.
- [ ] Configuration is normalized.
- [ ] Configuration can be cached.

## Drivers

- [ ] Libvips driver works where available.
- [ ] Imagick driver works where available.
- [ ] GD driver works as fallback.
- [ ] Driver capabilities are detectable.
- [ ] Driver selection is deterministic.

## Operations

- [ ] Geometry operations work.
- [ ] Color operations work.
- [ ] Effects work.
- [ ] Watermark/overlay works.
- [ ] Operation ordering is deterministic.
- [ ] Custom operations have an extension path.

## Encoders

- [ ] JPEG works.
- [ ] PNG works.
- [ ] WebP works.
- [ ] AVIF works where supported.
- [ ] Encoder capability detection works.

## Optimizers

- [ ] External tools are detected.
- [ ] Optimizers are optional.
- [ ] Native fallback exists where appropriate.
- [ ] Optimization failures are handled correctly.

## Storage

- [ ] Local adapter works.
- [ ] S3-compatible adapter works.
- [ ] S3 can be configured.
- [ ] DigitalOcean Spaces can be configured.
- [ ] Cloudflare R2 can be configured.
- [ ] Custom adapter contract exists.
- [ ] URLs can be generated.
- [ ] Temporary files are cleaned.
- [ ] Remote storage does not require a permanent local mirror.

## Generation

- [ ] Deterministic derivative identity works.
- [ ] Complete pipeline works.
- [ ] Same inputs produce the same identity.
- [ ] Meaningful configuration changes produce new identity.
- [ ] Pipeline is callable by future CLI/queue/runtime services.

## Performance

- [ ] No GeneratedImage database table exists.
- [ ] No one-row-per-derivative database model exists.
- [ ] Processing does not perform unnecessary Craft queries.
- [ ] Large source images are handled responsibly.
- [ ] Configuration is not repeatedly rebuilt unnecessarily.

## Tests

- [ ] Unit tests cover core contracts.
- [ ] Configuration tests pass.
- [ ] Driver tests pass.
- [ ] Operation tests pass.
- [ ] Encoder tests pass where supported.
- [ ] Storage tests pass.
- [ ] Integration pipeline tests pass.

---

# What Phase 2 Is Allowed To Assume

After Phase 1 is complete, Phase 2 may assume the existence of:

```text
ConfigurationResolver
Profile/Variant model
Image Driver Registry
Operation Registry
Encoder Registry
Optimizer Registry
Storage Registry
Generation Identity
Image Processing Pipeline
Storage Result
Domain Exceptions
Capability Detection
```

Phase 2 must build on these.

It must not replace them.

---

# What Phase 3 Is Allowed To Assume

After Phase 2 is complete, Phase 3 may assume:

```text
Generation Service
CLI generation
Queue generation
Runtime generation
Signed URLs
Twig integration
Responsive image generation
```

Phase 3 must use these existing services for the Playground, diagnostics, and Control Panel.

---

# Rules Against Architectural Drift

The following are explicit prohibitions.

## Do not create another processing pipeline

There must be one core pipeline.

## Do not create another configuration resolver

There must be one configuration resolution system.

## Do not make storage provider logic leak into processing

Processing should not know whether the output goes to S3, R2, Spaces, or local storage.

## Do not make encoders responsible for storage

Encoding produces image output.

Storage stores it.

## Do not make operations responsible for encoding

Operations transform image data.

Encoding produces the output format.

## Do not make Twig responsible for generation internals

Twig should use application services.

## Do not make the database the source of truth for derivatives

Derivative identity and storage are the source of generated-image state.

---

# Final Phase 1 Principle

Phase 1 should be boring in the best possible way.

It should provide a small number of strong, reliable services that everything else can build on:

```text
Resolve
Process
Encode
Optimize
Store
```

If these foundations are correct, Phase 2 can add CLI, queues, lazy generation, signed URLs, and Twig integration without changing the image engine.

Phase 3 can then add the Control Panel and Playground without changing the underlying processing model.

**Build the engine once. Keep it stable. Build everything else on top of it.**
