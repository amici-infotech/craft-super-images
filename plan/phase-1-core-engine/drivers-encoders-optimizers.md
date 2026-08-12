# Phase 1 — Drivers, Encoders & Optimizers

## Purpose

This document defines the image-processing backend architecture for **Super Images**.

The responsibilities are deliberately separated:

```text
Driver
  ↓
Image manipulation

Encoder
  ↓
Convert the processed image into the requested output format

Optimizer
  ↓
Optimize the final encoded representation
```

The generation engine must be able to change the image library, encoder, or optimizer without changing Twig, CLI, queue, storage, or configuration-resolution code.

---

## 1. Supported Processing Stack

### Drivers

Initial drivers:

```text
1. Libvips
2. Imagick
3. GD
```

Preferred default:

```text
Libvips
```

Fallback order:

```text
Libvips → Imagick → GD
```

Fallback is capability-based. A driver must not silently skip an operation it cannot perform.

### Output formats

Initial first-class formats:

```text
JPEG
PNG
WebP
AVIF
```

The architecture must allow additional formats later.

### External tools

The initial supported external tool set should include:

```text
cwebp
jpegoptim
oxipng
optipng
pngquant
avifenc
```

No external binary is mandatory for the basic plugin installation. Native encoding is the default where it is sufficient; external tools are optional, configurable implementations.

---

## 2. Canonical Pipeline

The processing pipeline is:

```text
Source Image
     ↓
Image Driver
     ↓
Operations
     ↓
Encoder
     ↓
Optimizer (optional)
     ↓
Output Validation
     ↓
Storage
```

The Generation Service orchestrates this pipeline but does not implement driver, encoder, optimizer, or storage behavior itself.

---

# 3. Image Drivers

## 3.1 Driver Responsibility

A driver is responsible for manipulating an image.

It may implement:

- load;
- create;
- resize;
- crop;
- fit;
- fill;
- rotate;
- flip;
- sharpen;
- blur;
- brightness;
- contrast;
- saturation;
- grayscale;
- watermark;
- dimension inspection;
- metadata operations;
- format/capability detection.

A driver must not:

- upload files;
- generate public URLs;
- resolve Craft configuration;
- select storage;
- know about Twig;
- know about Asset Fields;
- directly execute arbitrary shell commands.

---

## 3.2 Driver Contract

Conceptually:

```php
interface ImageDriverInterface
{
    public function load(SourceImage $source): Image;

    public function apply(
        Image $image,
        Operation $operation,
    ): Image;

    public function dimensions(Image $image): Dimensions;

    public function supports(string $operation): bool;

    public function capabilities(): DriverCapabilities;
}
```

Exact method signatures may evolve during implementation. The responsibility boundary must not.

---

## 3.3 Driver Manager

Driver selection belongs to:

```text
DriverManager
```

Responsibilities:

- register drivers;
- discover available drivers;
- select the configured/preferred driver;
- check capabilities;
- apply fallback rules;
- expose diagnostics.

The Generation Service must ask the Driver Manager for a driver rather than checking PHP extensions itself.

---

## 3.4 Libvips

Libvips is the preferred production driver because it is well suited to high-throughput server-side image processing and generally has strong memory/performance characteristics.

The implementation should avoid unnecessary conversions between representations.

Preferred flow:

```text
Source
 ↓
Libvips image
 ↓
Operations
 ↓
Encoder
```

Avoid unnecessary intermediate conversions such as:

```text
Source
 ↓
Libvips
 ↓
temporary PNG
 ↓
Imagick
 ↓
temporary file
 ↓
Libvips
```

unless a concrete capability requires them.

---

## 3.5 Imagick

Imagick is the primary fallback where Libvips is unavailable.

Imagick-specific objects must remain behind the driver boundary. They must never leak into Twig, configuration, storage, or generation identity.

---

## 3.6 GD

GD is the final baseline fallback.

GD has more limitations than Libvips and Imagick. Its capability object must expose those limitations instead of pretending that all drivers are equivalent.

For example:

```text
AVIF support: unavailable
advanced color operations: limited
```

---

## 3.7 Driver Capabilities

Every driver must expose capabilities through a normalized structure such as:

```text
DriverCapabilities
├── formats
├── operations
├── animation support
├── alpha support
├── metadata support
├── color-space support
└── limits
```

Capability detection is centralized and cached where appropriate.

Do not scatter checks such as:

```php
extension_loaded('imagick')
```

throughout the plugin.

---

## 3.8 Driver Differences and Generation Identity

Different drivers can produce different image bytes from the same source and operations.

Therefore the generation identity must be capable of accounting for the selected driver when driver behavior can affect output.

Conceptually:

```text
source + operations + driver A
        ≠
source + operations + driver B
```

The exact identity policy belongs to `GenerationIdentityService`.

Driver/package versions may also affect output. They should only participate in identity when required by the chosen invalidation policy; do not cause unnecessary global regeneration merely because an unrelated system package changed.

---

## 3.9 Operation Support

Before executing an operation:

```text
Operation
   ↓
Driver capability
   ↓
supported?
```

If unsupported, Super Images must either use an explicitly supported fallback or fail clearly.

It must never silently ignore an operation.

---

# 4. Encoders

## 4.1 Encoder Responsibility

An encoder converts a processed image into a final output representation.

Examples:

```text
JPEG
PNG
WebP
AVIF
```

Encoder responsibilities include:

- output format;
- quality;
- compression;
- lossless/lossy mode;
- format-specific options;
- metadata policy where relevant.

The encoder must not:

- perform image composition operations;
- upload files;
- resolve Craft configuration;
- select storage;
- generate URLs.

---

## 4.2 Encoder Contract

Conceptually:

```php
interface EncoderInterface
{
    public function format(): string;

    public function encode(
        Image $image,
        EncodeOptions $options,
    ): EncodedImage;

    public function supports(Image $image): bool;

    public function capabilities(): EncoderCapabilities;
}
```

Exact signatures may evolve, but encoding remains the only responsibility.

---

## 4.3 Encoder Manager

The Encoder Manager selects an implementation based on:

```text
requested format
+ configured/preferred encoder
+ available capabilities
```

Conceptually:

```text
Format: webp
      ↓
EncoderManager
      ↓
preferred encoder
      ↓
capability check
      ↓
Encoder
```

---

## 4.4 Native vs External Encoders

Both are supported.

Examples:

```text
WebP
├── native Libvips/driver encoder
└── cwebp

AVIF
├── native Libvips/driver encoder
└── avifenc
```

The application must not care whether encoding happens inside a library or through an external process.

External implementations should be preferred only when they provide a concrete benefit such as better compression, required functionality, or a project-specific standard.

---

# 5. Format-Specific Decisions

## 5.1 JPEG

Support should include:

```text
quality
progressive output
metadata policy
subsampling where supported
```

Possible optimizer:

```text
jpegoptim
```

---

## 5.2 PNG

Support should include:

```text
lossless output
compression level
alpha channel
metadata policy
```

Supported optimizers:

```text
oxipng
optipng
pngquant
```

Important distinction:

```text
oxipng / optipng
    ↓
lossless optimization

pngquant
    ↓
lossy palette quantization
```

`pngquant` must never be silently applied to all PNGs. It must be explicitly configured or selected by a profile/policy that allows lossy PNG optimization.

---

## 5.3 WebP

Support should include:

```text
lossy
lossless
quality
alpha
compression effort
metadata policy
```

Possible implementations:

```text
native driver encoder
cwebp
```

Do not encode with `cwebp` and then unnecessarily re-encode with another WebP optimizer.

---

## 5.4 AVIF

AVIF is a first-class output format.

Potential implementations:

```text
native driver encoder
avifenc
```

Format-specific options may include:

```text
quality
speed
lossless mode
chroma/subsampling
```

Only expose options supported by the selected encoder.

---

# 6. Optimizers

## 6.1 Optimizer Responsibility

An optimizer works on the final encoded representation.

It may:

- reduce metadata;
- recompress;
- optimize entropy coding;
- reduce file size;
- quantize when explicitly configured.

It must not:

- resize;
- crop;
- sharpen;
- watermark;
- change composition.

Those are image operations.

---

## 6.2 Optimizer Contract

Conceptually:

```php
interface OptimizerInterface
{
    public function name(): string;

    public function supports(string $format): bool;

    public function optimize(
        EncodedImage $image,
        OptimizeOptions $options,
    ): OptimizedImage;

    public function capabilities(): OptimizerCapabilities;
}
```

---

## 6.3 Optimizer Manager

The Optimizer Manager determines:

```text
format
+
configured optimizer
+
available optimizer
```

Example:

```text
JPEG → jpegoptim
PNG  → oxipng
WebP → none / encoder-specific
AVIF → none / avifenc when configured
```

No optimizer is a valid configuration.

---

# 7. Recommended Initial Tooling

The initial supported tool stack is:

| Format | Primary encoder | Optional external encoder/optimizer |
|---|---|---|
| JPEG | Native driver/library | `jpegoptim` |
| PNG | Native driver/library | `oxipng`, `optipng`, `pngquant` |
| WebP | Native driver/library | `cwebp` |
| AVIF | Native driver/library | `avifenc` |

Recommended defaults:

```text
Driver:
    Libvips

JPEG:
    native encoder
    optional jpegoptim

PNG:
    native encoder
    optional oxipng

WebP:
    native encoder
    optional cwebp

AVIF:
    native encoder
    optional avifenc
```

These are defaults, not hard dependencies.

---

# 8. External Process Architecture

All external binaries must be isolated behind:

```text
ProcessRunner
```

Encoders and optimizers must never call:

```php
exec()
shell_exec()
system()
passthru()
```

directly.

---

## 8.1 Process Runner Responsibilities

`ProcessRunner` handles:

- executable resolution;
- argument passing;
- process execution;
- timeout;
- exit code;
- stdout;
- stderr;
- safe diagnostics;
- temporary file handling where required.

Prefer argument arrays:

```php
$processRunner->run([
    $binary,
    '--quality',
    (string) $quality,
    $inputPath,
    '-o',
    $outputPath,
]);
```

Do not construct commands by concatenating untrusted values into shell strings.

---

## 8.2 Tool Detection

Supported tools should be represented by a centralized tool registry/detection service:

```text
ToolRegistry
├── cwebp
├── jpegoptim
├── oxipng
├── optipng
├── pngquant
└── avifenc
```

Detection should expose:

```text
available
path
version
capabilities
```

Detection results must be cached.

Do not run `--version`, `which`, or equivalent commands on every frontend request.

---

## 8.3 Required vs Optional Tools

A tool may be:

```text
preferred
optional
required
fallback
disabled
```

Example:

```text
jpegoptim
    enabled: true
    required: false
```

If it is unavailable and optional:

```text
use the encoded output without jpegoptim
```

If it is explicitly required:

```text
fail generation
```

The behavior must be deterministic and visible in diagnostics.

---

# 9. Temporary Files

External tools may require temporary files.

Temporary files must:

- use a controlled temporary directory;
- use unpredictable names;
- have appropriate permissions;
- never be derived directly from untrusted paths;
- be deleted after processing;
- never become permanent derivative storage.

Temporary processing storage and permanent storage are separate concepts.

---

# 10. Avoid Unnecessary Re-encoding

Never perform needless format conversions.

Bad:

```text
JPEG
 ↓
PNG
 ↓
WebP
```

Preferred:

```text
Source
 ↓
Driver operations
 ↓
WebP encoder
```

Likewise:

```text
Source
 ↓
Driver operations
 ↓
AVIF encoder
```

Each requested format should be generated from the processed source representation whenever possible.

---

# 11. Multiple Formats

If an Asset needs:

```text
jpg
webp
avif
```

the engine should avoid repeating expensive image operations unnecessarily.

Preferred conceptual flow:

```text
Source
 ↓
Load
 ↓
Common operations
 ↓
Processed image
 ├── JPEG encoder
 ├── WebP encoder
 └── AVIF encoder
```

The implementation must ensure that sharing the processed representation does not create unsafe mutable-state bugs. If the underlying driver requires cloning, cloning must be handled by the driver abstraction rather than by callers.

---

# 12. Multiple Variants

For variants such as:

```text
576
768
992
1280
1600
```

the first implementation may process each variant independently for correctness and simplicity.

Future optimization may reuse:

```text
source decoding
common operation stages
intermediate representations
```

only after profiling demonstrates a meaningful benefit.

Do not introduce complicated graph processing prematurely.

---

# 13. Quality Semantics

Quality is format-specific.

Do not assume that:

```text
quality = 80
```

has identical meaning for:

```text
JPEG
WebP
AVIF
```

Each encoder must normalize quality according to its own format semantics.

A common high-level quality value may exist, but format-specific overrides must be supported.

---

# 14. Encoder Options

Conceptually:

```php
'encoder' => [
    'quality' => 80,
],
```

Format-specific overrides may look like:

```php
'formats' => [
    'webp' => [
        'quality' => 82,
    ],

    'avif' => [
        'quality' => 65,
    ],
],
```

The final configuration schema is defined by `configuration.md` and must be normalized before reaching an encoder.

---

# 15. Optimizer Options

Conceptually:

```php
'optimizer' => [
    'name' => 'oxipng',
    'options' => [
        'level' => 4,
    ],
],
```

Optimizer-specific options must be validated by the optimizer implementation.

Do not expose unrestricted command-line arguments as a general-purpose configuration escape hatch.

---

# 16. Driver/Encoder Compatibility

An encoder may require a particular image representation.

For example, a native Libvips encoder naturally consumes a Libvips image, while an external encoder may require a temporary file.

That conversion belongs inside the encoder/driver integration.

The Generation Service should continue to work with the abstract flow:

```text
Image
 ↓
Encoder
 ↓
EncodedImage
```

It must not contain provider-specific conversion logic.

---

# 17. Capability Validation

Before expensive processing where possible:

```text
Requested format
        ↓
EncoderManager
        ↓
available encoder?
        ↓
driver compatible?
        ↓
requested options supported?
```

Likewise for optimization:

```text
Encoded format
        ↓
OptimizerManager
        ↓
configured optimizer
        ↓
available?
        ↓
supports format?
```

Unsupported combinations must fail clearly or use an explicitly configured fallback.

Do not silently produce an output that does not match the requested configuration.

---

# 18. Custom Drivers

Third-party plugins must be able to register custom drivers without modifying Super Images core.

Conceptually:

```php
DriverRegistry::register(
    name: 'custom-driver',
    driver: CustomDriver::class,
);
```

The registration mechanism should use the plugin's public extension/event API.

A custom driver must satisfy the public driver contract.

---

# 19. Custom Encoders

Third-party plugins must be able to register encoders for new formats or alternate implementations.

Example:

```text
Format: jxl
Encoder: CustomJxlEncoder
```

The encoder declares its capabilities and supported formats.

---

# 20. Custom Optimizers

Third-party plugins must be able to register optimizers.

Example:

```text
Optimizer:
    company-image-optimizer
```

The optimizer declares which formats it supports and what options it accepts.

---

# 21. Extension Events

Potential events include:

```text
RegisterImageDrivers
RegisterImageEncoders
RegisterImageOptimizers

BeforeImageEncoding
AfterImageEncoding

BeforeImageOptimization
AfterImageOptimization
```

Events are extension points, not replacements for the core processing pipeline.

Internal control flow should continue to use explicit services and contracts.

---

# 22. Error Handling

Errors should identify the subsystem.

Examples:

```text
DriverUnavailableException
UnsupportedOperationException
EncoderUnavailableException
UnsupportedFormatException
OptimizerUnavailableException
ExternalProcessException
```

Messages should provide actionable diagnostics without exposing secrets.

---

# 23. Logging

External tool failures may record:

```text
tool
version
exit code
duration
safe execution context
stderr
```

Never log:

```text
AWS credentials
secret URLs
signed URLs
authentication headers
```

Paths should be sanitized where appropriate.

---

# 24. Performance Requirements

The subsystem must be designed for high-throughput generation.

Requirements:

- cache tool discovery;
- avoid repeated extension checks;
- avoid unnecessary process spawning;
- avoid unnecessary format conversions;
- reuse processed images where safe;
- avoid unnecessary temporary files;
- avoid repeated source decoding where practical;
- do not run optional optimizers unless configured;
- do not inspect external tools during normal frontend rendering.

---

# 25. Frontend Performance Rule

Twig rendering must never trigger expensive capability discovery.

Prohibited:

```text
Twig
 ↓
check cwebp
 ↓
check jpegoptim
 ↓
check oxipng
```

Capability information must already be known or cheaply available from cache.

---

# 26. Diagnostics

The subsystem must expose enough information for future Control Panel diagnostics.

Example:

```text
Drivers
────────────────────
Libvips     available   8.x
Imagick     available   3.x
GD          available

Encoders
────────────────────
JPEG        native
PNG         native
WebP        native + cwebp
AVIF        native + avifenc

Optimizers
────────────────────
jpegoptim   available
oxipng      available
optipng     unavailable
pngquant    available
```

Phase 3 will expose this information in the Control Panel.

---

# 27. Testing Requirements

Tests must exist at several levels.

### Unit

Test:

- driver selection;
- encoder selection;
- optimizer selection;
- capability matching;
- option normalization;
- registry behavior.

### Contract

Every driver, encoder, and optimizer implementation must pass its public contract tests.

### Integration

Where the dependency exists, test against real:

```text
Libvips
Imagick
GD
cwebp
jpegoptim
oxipng
optipng
pngquant
avifenc
```

Optional environment dependencies may cause explicit test skips, but must not produce silent false positives.

### Pipeline

Test the full flow:

```text
Source
 ↓
Driver
 ↓
Operations
 ↓
Encoder
 ↓
Optimizer
 ↓
Validated output
```

---

# 28. Recommended Initial Implementation Order

Implement in this order:

```text
1. Driver contracts
2. Driver capabilities
3. Driver registry/manager
4. Libvips driver
5. Imagick driver
6. GD driver
7. Encoder contracts
8. Encoder registry/manager
9. Native JPEG/PNG/WebP/AVIF encoding
10. ProcessRunner
11. Tool detection
12. jpegoptim
13. oxipng
14. optipng
15. pngquant
16. cwebp
17. avifenc
18. Optimizer contracts/manager
19. Integration tests
```

The exact implementation order can change if a dependency requires it, but the responsibility boundaries must remain intact.

---

# 29. Architectural Invariants

### Invariant 1

Drivers manipulate images.

### Invariant 2

Encoders create final output formats.

### Invariant 3

Optimizers optimize encoded output.

### Invariant 4

Storage is outside this subsystem.

### Invariant 5

External binaries are accessed only through `ProcessRunner`.

### Invariant 6

Capability detection is centralized and cached.

### Invariant 7

Optional tools do not become mandatory dependencies.

### Invariant 8

Unsupported operations are never silently ignored.

### Invariant 9

Driver differences are accounted for by generation identity where they can affect output.

### Invariant 10

Multiple formats should reuse processed image state where it is safe to do so.

### Invariant 11

Frontend requests must not repeatedly inspect external binaries.

### Invariant 12

Third-party drivers, encoders, and optimizers can be registered without modifying core processing code.

### Invariant 13

External optimization must never silently introduce lossy behavior where lossless output was requested.

### Invariant 14

The engine must never perform an unnecessary encode/decode cycle merely to fit an implementation detail of one provider.

---

# Final Rule

Keep these responsibilities independent:

```text
DRIVER
"How do I manipulate the image?"

ENCODER
"How do I represent this processed image as JPEG/PNG/WebP/AVIF?"

OPTIMIZER
"How do I make that final representation smaller while respecting its configured rules?"
```

The Generation Service orchestrates them, but none of them should become responsible for the others.

This separation is what allows Super Images to evolve its processing stack without changing the public generation API, storage architecture, CLI, queue system, or frontend integration.
