# Phase 1 — Architecture

This document defines the internal architecture of **Super Images**.

It is the primary technical architecture document for Phase 1 and must be read before implementing core services.

The architecture is intentionally designed around one principle:

> **The image-generation engine must be independent from how images are requested, configured, encoded, optimized, stored, or presented.**

The engine must be reusable by CLI, Queue, Runtime Action URLs, Twig, Control Panel Playground, and future integrations without creating separate processing implementations.

---

## 1. Architectural Goals

Super Images must be:

- Fast
- Query-sparing
- Deterministic
- Extensible
- Storage-independent
- Driver-independent
- Testable
- Safe
- Craft CMS native
- Suitable for large Asset libraries

The architecture must avoid unnecessary database state and unnecessary work during normal frontend requests.

---

## 2. High-Level Architecture

```text
Integration Layer
  Twig | CLI | Queue | Runtime URL | Control Panel | API
                         ↓
Application Layer
  Generation Service | Configuration Resolver | Capability Services
                         ↓
Domain Layer
  Profiles | Variants | Generation Definition | Identity | Operations
                         ↓
Infrastructure Layer
  Drivers | Encoders | Optimizers | Storage | Tool Detection
```

Dependency direction:

```text
Integration
    ↓
Application
    ↓
Domain
    ↓
Infrastructure
```

Infrastructure implementations may depend on domain contracts. Domain concepts must not depend directly on specific infrastructure implementations.

---

## 3. Core Processing Pipeline

There must be **one canonical image-generation pipeline**:

```text
Craft Asset
    ↓
Source Resolver
    ↓
Configuration Resolver
    ↓
Generation Definition
    ↓
Generation Identity
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
Generation Result
```

CLI, queue, runtime generation, and Playground must eventually call this same pipeline.

---

## 4. Generation Service

The main application entry point is conceptually:

```text
GenerationService
```

Example:

```php
$result = $generationService->generate($generationRequest);
```

The Generation Service is an orchestrator. It must not itself implement resizing, encoding, optimization, S3 communication, or format-specific image processing.

---

## 5. Generation Request

A generation request represents one desired derivative:

```text
GenerationRequest
├── Asset
├── Profile
├── Variant
├── Format
├── Processing options
├── Encoder options
├── Optimizer options
└── Storage context
```

Use a focused value object rather than an unrestricted associative array wherever practical.

The request describes **what should be generated** and must not contain provider-specific storage implementation details.

---

## 6. Processing Context

During processing, shared state can be represented by a processing context:

```text
ProcessingContext
├── GenerationRequest
├── SourceImage
├── Driver
├── Operation state
├── Encoded output
├── Optimization result
└── Generation identity
```

Do not turn the context into a god object. Only information required across pipeline stages belongs in shared context.

---

## 7. Generation Result

The pipeline returns a structured result:

```text
GenerationResult
├── Success
├── Generation Identity
├── Storage Path
├── Public URL
├── Format
├── Width
├── Height
├── File Size
├── MIME Type
├── Processing Duration
└── Optional diagnostic metadata
```

It must be useful to Twig, CLI, Queue, Playground, and diagnostics without requiring them to understand internal processing.

---

## 8. Source Resolver

The Source Resolver converts an allowed image source into a normalized source usable by the selected driver.

Supported source kinds:

```text
1. Craft Asset
2. Local path / local URL path   e.g. /images/abc.png
3. Remote / CDN URL              e.g. https://cdn.example.com/hero.jpg
```

Responsibilities:

- detect/normalize source kind;
- resolve Craft Assets through Craft APIs;
- resolve local paths only inside configured allow-listed roots;
- fetch remote/CDN URLs only for configured allow-listed hosts with SSRF protections;
- obtain a usable source file/stream;
- handle Craft Assets stored on remote volumes where supported;
- determine source metadata;
- compute a stable source identity for generation identity;
- provide a temporary local source when required;
- clean up temporary resources.

It must not process, encode, optimize, or store derivatives.

Conceptual model:

```text
SourceReference
├── kind: asset | localPath | remoteUrl
├── assetId? 
├── path?
├── url?
└── normalized identity inputs
        ↓
SourceResolver
        ↓
SourceImage (bytes/path + metadata + identity)
```

Local-path and remote-URL support is mandatory for the product, not optional polish.

---

## 9. Craft Asset Boundary

Craft Asset APIs should be isolated at the application/infrastructure boundary.

Prefer:

```text
Craft Asset | local path | remote URL
    ↓
Source Resolver
    ↓
Internal Source representation
    ↓
Image Engine
```

This keeps the image engine independently testable and reusable for non-Asset sources.

---

## 10. Configuration Architecture

Configuration has a dedicated subsystem centered around:

```text
ConfigurationResolver
```

Precedence:

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

The resolver produces a normalized internal configuration representation.

---

## 11. One Configuration Resolver

This is prohibited:

```text
Twig → merge config
CLI → merge config differently
Queue → merge config differently
Playground → merge config differently
```

Instead:

```text
                    ConfigurationResolver
                           │
             ┌─────────────┼─────────────┐
             ↓             ↓             ↓
           Twig           CLI          Queue
             ↓             ↓             ↓
                  same effective config
```

One resolver. One precedence model. One normalization process.

---

## 12. Profiles and Variants

Profiles are reusable generation definitions.

Conceptually:

```text
Profile
├── Name
├── Variants
├── Defaults
├── Formats
├── Operations
├── Encoder options
├── Optimizer options
└── Generation settings
```

Variants represent individual output definitions, for example:

```php
'lg' => [
    'width' => 992,
]
```

A Variant describes transformation intent. It must not know about S3, local directories, URL hosts, Twig, CLI, or queues.

---

## 13. Generation Definition

Before processing, effective configuration is converted into a normalized generation definition:

```text
GenerationDefinition
├── Asset source identity
├── Profile
├── Variant
├── Operations
├── Output format
├── Encoder configuration
├── Optimizer configuration
└── Processing version
```

This is the canonical description of one derivative and the primary input to generation identity calculation.

---

## 14. Generation Identity

Generation identity must be deterministic:

```text
Generation Identity =
hash(
    source identity,
    profile,
    variant,
    format,
    operations,
    encoder configuration,
    optimizer configuration,
    processing schema version
)
```

The exact hashing/path algorithm is implemented centrally. No other subsystem may independently calculate derivative paths.

A meaningful configuration or processing implementation change must be capable of producing a new identity.

---

## 15. Image Driver Architecture

Image processing is driver-based.

Initial drivers:

```text
Libvips
Imagick
GD
```

Conceptually:

```text
ImageDriverInterface
├── load()
├── create()
├── resize()
├── crop()
├── rotate()
├── flip()
├── apply()
└── metadata/capability methods
```

The exact interface should be based on required operations rather than exposing every library-specific feature.

---

## 16. Driver Selection and Capabilities

Driver selection must be centralized:

```text
DriverManager
      ↓
Requested/preferred driver?
      ↓
Capability check
      ↓
Available driver
```

Preferred default:

```text
Libvips
```

Fallback:

```text
Imagick
    ↓
GD
```

Do not scatter extension checks throughout the plugin.

Capability detection should cover libraries, image formats, and optional external tools. Results should be cached appropriately.

---

## 17. Operation Architecture

Operations are composable image transformations.

```text
OperationInterface
```

Example:

```text
Resize
    ↓
Crop
    ↓
Sharpen
    ↓
Watermark
```

Operations execute in deterministic order.

They must not write files, upload to S3, generate URLs, know about Asset fields/Twig/CLI, or choose encoders.

---

## 18. Operation Registry

Operations should be discoverable through an `OperationRegistry`:

```text
resize
crop
fit
fill
rotate
flip
sharpen
blur
brightness
contrast
saturation
grayscale
watermark
...
```

Third-party operations should eventually be registerable without modifying core source.

---

## 19. Encoder Architecture

Encoding is a separate layer.

```text
EncoderInterface
```

Initial formats:

```text
JPEG
PNG
WebP
AVIF
```

Selection:

```text
Format
   ↓
EncoderRegistry
   ↓
Encoder
```

The architecture supports both native library encoding and external binary encoding.

Example:

```text
WebP
 ├── Libvips native
 └── cwebp
```

The rest of the pipeline must not care which implementation is selected.

---

## 20. Optimizer Architecture

Optimization is separate from encoding.

```text
OptimizerInterface
```

Examples:

```text
JPEG → jpegoptim
PNG  → oxipng
PNG  → optipng
PNG  → pngquant
WebP → cwebp where appropriate
AVIF → avifenc where appropriate
```

No optimizer is a valid state. The optimizer operates on the final encoded representation.

Default pipeline:

```text
Image Operations
       ↓
Encoder
       ↓
Optimizer
       ↓
Validation
```

---

## 21. Storage Architecture

Storage is adapter-based:

```text
StorageAdapterInterface
├── write()
├── exists()
├── delete()
├── url()
└── metadata/capability methods as required
```

Initial adapters:

```text
LocalStorageAdapter
S3StorageAdapter
```

The S3 adapter covers:

- Amazon S3
- DigitalOcean Spaces
- Cloudflare R2
- compatible S3 providers

A custom adapter must be registerable without modifying core.

---

## 22. Temporary Storage Boundary

Processing may require local files even when permanent storage is remote.

```text
Temporary processing storage
             ≠
Permanent derivative storage
             ≠
Existence marker storage
```

Temporary storage exists only for processing and must never become an implicit permanent image cache.

### Existence markers

For remote/CDN derivative storage, Super Images may maintain tiny local existence markers under private Craft storage, for example:

```text
storage/super-images/markers/<identity...>
```

Rules:

- markers are not image binaries;
- markers are never placed in the public web folder;
- markers help generation/CLI/runtime avoid repeated remote HEAD checks;
- normal Twig rendering must not read markers;
- deleting a remote derivative should also clear its marker when cleanup knows about it.

---

## 23. Generation Orchestration

The Generation Service coordinates:

```text
1. Validate GenerationRequest
2. Resolve source Asset
3. Resolve effective configuration
4. Build GenerationDefinition
5. Calculate GenerationIdentity
6. Select ImageDriver
7. Load source
8. Execute Operations
9. Select Encoder
10. Encode
11. Select Optimizer
12. Optimize
13. Validate output
14. Store through StorageAdapter
15. Return GenerationResult
```

The service remains an orchestrator.

---

## 24. Output Validation and Atomic Storage

Before permanent storage, validate:

- output exists;
- output is readable;
- expected format is correct;
- MIME type is correct;
- dimensions are valid;
- file size is greater than zero;
- no processing error occurred.

Where supported:

```text
temporary object/file
      ↓
validation
      ↓
atomic/final write
```

For remote storage, upload only the validated final output.

---

## 25. No GeneratedImage Model

Do not create:

```text
GeneratedImage
GeneratedTransform
ImageDerivative
```

database models merely to track generated files.

Derivative state is represented by:

```text
GenerationDefinition
+
GenerationIdentity
+
Storage
```

There is no one-row-per-derivative database state.

---

## 26. Query Discipline

The image engine should not query the database unnecessarily.

Do not introduce persistence for generated derivative state unless a future requirement proves it necessary.

The normal frontend path must not depend on a generated-image database.

---

## 27. Integration Independence

### Twig

```text
Twig
 ↓
Application Service
 ↓
Generation Engine
```

The engine must not depend on Twig.

### CLI

```text
CLI
 ↓
Generation Service
```

The service returns structured results/exceptions. The CLI decides how to display them.

### Queue

```text
Queue Job
 ↓
Generation Service
```

The engine performs one generation. The queue decides scheduling and batching.

### Control Panel

The Playground must call the same Generation Service. It must not create a second processing implementation.

---

## 28. Events and Registries

Potential lifecycle events:

```text
beforeGeneration
 afterGeneration
beforeProcessing
 afterProcessing
beforeEncoding
 afterEncoding
beforeOptimization
 afterOptimization
beforeStorage
 afterStorage
```

Events are for extension and observation, not primary internal control flow.

Registries should exist for genuinely extensible concepts:

```text
DriverRegistry
OperationRegistry
EncoderRegistry
OptimizerRegistry
StorageRegistry
```

---

## 29. Dependency Injection

Services should use Craft/Yii dependency injection and prefer constructor injection.

Example:

```php
final class GenerationService
{
    public function __construct(
        private ConfigurationResolver $configurationResolver,
        private SourceResolver $sourceResolver,
        private DriverManager $driverManager,
        private EncoderManager $encoderManager,
        private OptimizerManager $optimizerManager,
        private StorageManager $storageManager,
        private GenerationIdentityService $identityService,
    ) {
    }
}
```

Avoid service-locator usage inside domain classes.

---

## 30. Domain vs Infrastructure

Domain concepts should not depend directly on external binaries.

Domain:

```text
EncoderDefinition
OptimizerDefinition
GenerationDefinition
```

Infrastructure:

```text
CwebpEncoder
JpegoptimOptimizer
LibvipsDriver
S3StorageAdapter
```

---

## 31. External Process Execution

External binaries must be isolated behind infrastructure services.

Do not call `shell_exec()`, `exec()`, or `proc_open()` directly from operations or application services.

Use a dedicated process execution abstraction:

```text
ProcessRunner
    ↓
safe command execution
    ↓
stdout/stderr/exit code
```

Requirements:

- no shell interpolation of untrusted values;
- proper argument escaping;
- timeout support where practical;
- exit-code handling;
- stderr capture;
- useful diagnostics;
- no secret leakage.

---

## 32. Security Boundary

Treat all external inputs as untrusted, especially runtime transformation parameters, Asset IDs, local paths, remote URLs, format names, operation names, storage configuration, and external tool arguments.

Never allow arbitrary runtime input to become an arbitrary shell command or arbitrary filesystem path.

Local-path sources require allow-listed roots and canonicalization.

Remote URL sources require host allow-lists, SSRF protections, timeouts, and size limits.

Storage credentials must never appear in logs, generated URLs, generation identity, Twig output, markers, or diagnostics.

See `../security.md` for the full precaution list.

---

## 33. Immutability and Error Boundaries

Where practical, keep value objects immutable:

```text
GenerationRequest
GenerationDefinition
GenerationIdentity
FormatDefinition
VariantDefinition
```

Convert infrastructure failures into domain-specific exceptions at boundaries.

Example:

```text
cwebp exits 127
       ↓
ProcessRunner
       ↓
EncoderUnavailableException
```

or:

```text
S3 upload fails
       ↓
S3StorageAdapter
       ↓
StorageException
```

---

## 34. Observability and Performance

The architecture should allow optional metrics such as:

```text
generation count
generation duration
processing duration
encoding duration
optimization duration
storage duration
output size
compression ratio
```

Do not introduce a mandatory analytics database in Phase 1.

Performance-sensitive areas include configuration resolution, generation identity, driver/encoder selection, capability detection, and storage URL generation.

Capability information should be cached. External binaries must not be executed during ordinary frontend rendering.

---

## 35. Testing Architecture

Tests should be layered:

```text
Unit
 ↓
Contract
 ↓
Integration
 ↓
Pipeline
```

Unit tests cover value objects, configuration, identity, operations, and registries.

Contract tests cover drivers, encoders, optimizers, and storage implementations.

Integration tests cover real image libraries, local filesystem, test/mocked S3-compatible storage, and external binaries where available.

Pipeline tests cover the complete:

```text
Asset
 ↓
Config
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
```

Optional dependencies may be skipped explicitly when unavailable, but core behavior must remain tested.

---

## 36. Package Structure

A conceptual structure is:

```text
src/
├── Plugin.php
├── contracts/
├── exceptions/
├── models/
├── services/
│   ├── Configuration/
│   ├── Generation/
│   ├── Identity/
│   ├── Source/
│   └── Capability/
├── drivers/
├── operations/
├── encoders/
├── optimizers/
├── storage/
├── registries/
├── events/
└── support/
```

Exact namespace/folder choices may follow normal Craft plugin conventions, but responsibility boundaries must remain.

---

## 37. Naming and Abstraction Rules

Prefer explicit names:

```text
GenerationService
ConfigurationResolver
GenerationIdentityService
DriverManager
EncoderManager
OptimizerManager
StorageManager
SourceResolver
ProcessRunner
```

Avoid vague names such as `Helper`, `Utility`, `Processor`, or `Handler` unless the responsibility is genuinely clear.

Do not create abstractions without a concrete reason. Avoid patterns such as `FactoryFactory` or `PipelineResolverFactory` without a real extensibility, testing, provider isolation, performance, security, or domain requirement.

Do not create God classes. The Generation Service is an orchestrator, not an implementation dump.

---

## 38. Phase 2 Integration Contract

Phase 2 consumes:

```text
GenerationService
GenerationRequest
GenerationResult
GenerationIdentity
ConfigurationResolver
StorageManager
```

Phase 2 may add:

```text
GenerationManifest
RuntimeGenerationService
SignedUrlService
Queue jobs
Twig extensions
```

but these must call Phase 1 services.

---

## 39. Phase 3 Integration Contract

Phase 3 consumes:

```text
GenerationService
ConfigurationResolver
CapabilityService
StorageManager
DriverManager
EncoderManager
OptimizerManager
```

The Playground must use the existing Generation Service. The Control Panel must use the same configuration model. Diagnostics must consume existing capability services.

---

## 40. Architectural Invariants

These must remain true throughout the project:

1. There is one canonical image-generation pipeline.
2. There is one canonical configuration resolver.
3. There is no GeneratedImage database table.
4. Remote permanent storage does not require a permanent local image mirror.
5. Existence markers, if used, live only under private Craft `storage/`.
6. Craft Assets, local paths, and allow-listed remote URLs share the same pipeline.
7. Derivative identity is deterministic.
8. Operations do not know about storage.
9. Encoders do not know about storage.
10. Storage does not know about Twig.
11. Twig does not implement image processing.
12. CLI does not implement image processing.
13. External processes are isolated behind safe infrastructure.
14. Optional tools are capability-detected.
15. Normal frontend rendering does not process images.
16. Normal frontend rendering does not query generated-image state or markers.
17. Future phases build on Phase 1 rather than replacing it.

---

## 41. When Architecture May Change

Architecture is intentionally stable, but it is not sacred.

A change is justified only if the current design is proven to be:

- technically incorrect;
- incompatible with Craft CMS 5;
- insecure;
- unable to support a required feature;
- causing significant production performance problems;
- substantially more complex than a clearly superior alternative.

Do not change architecture because another pattern is fashionable, another implementation is slightly shorter, a class could be renamed, folders could be arranged differently, or another abstraction is theoretically possible.

If a significant change is required:

```text
Problem
   ↓
Evidence
   ↓
Proposed solution
   ↓
Impact
   ↓
Approval
   ↓
Implementation
```

Do not silently redesign the architecture during implementation.

---

## 42. Final Architecture

```text
                         ┌───────────────────┐
                         │   Craft Asset     │
                         └─────────┬─────────┘
                                   │
                                   ▼
                         ┌───────────────────┐
                         │ Source Resolver   │
                         └─────────┬─────────┘
                                   │
                                   ▼
                         ┌───────────────────┐
                         │ Config Resolver   │
                         └─────────┬─────────┘
                                   │
                                   ▼
                         ┌───────────────────┐
                         │ Generation        │
                         │ Definition        │
                         └─────────┬─────────┘
                                   │
                                   ▼
                         ┌───────────────────┐
                         │ Identity Service  │
                         └─────────┬─────────┘
                                   │
                                   ▼
                         ┌───────────────────┐
                         │ Generation        │
                         │ Service           │
                         └─────────┬─────────┘
                                   │
                    ┌──────────────┼──────────────┐
                    ▼              ▼              ▼
             Driver Manager   Operation       Encoder
                    │          Registry        Manager
                    │              │              │
                    └──────────────┼──────────────┘
                                   │
                                   ▼
                         ┌───────────────────┐
                         │ Optimizer Manager │
                         └─────────┬─────────┘
                                   │
                                   ▼
                         ┌───────────────────┐
                         │ Output Validation │
                         └─────────┬─────────┘
                                   │
                                   ▼
                         ┌───────────────────┐
                         │ Storage Manager   │
                         └─────────┬─────────┘
                                   │
                       ┌───────────┼───────────┐
                       ▼           ▼           ▼
                     Local        S3        Custom
                                  │
                         ┌────────┼────────┐
                         ▼        ▼        ▼
                       S3     Spaces      R2
```

The important architectural property is the separation:

```text
Resolve
   ↓
Describe
   ↓
Process
   ↓
Encode
   ↓
Optimize
   ↓
Validate
   ↓
Store
```

Everything above this engine is an integration.

Everything below it is an implementation.

Everything in the middle must remain stable enough that new integrations can be added without rewriting the engine.

---

# Final Rule

**Do not build features around the current UI, CLI, or Twig syntax.**

Build the core around the image-generation domain.

UI, CLI, Twig, runtime URLs, queue workers, and future APIs are consumers of the engine.

That is what allows Super Images to grow from an image-transform plugin into a complete Craft CMS image infrastructure system without repeatedly changing its foundation.
