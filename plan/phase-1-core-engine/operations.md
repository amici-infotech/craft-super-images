# Phase 1 — Operations

## Purpose

This document defines the image **operations** layer for **Super Images**.

Operations are the composable image-manipulation steps that run between source loading and encoding:

```text
Source Image
     ↓
Image Driver
     ↓
Operations   ← this document
     ↓
Encoder
     ↓
Optimizer
     ↓
Storage
```

An operation describes **what** should happen to an image.

A driver is responsible for **how** that happens for a given image library.

Encoding, optimization, storage, Twig, CLI, queues, and Control Panel must never implement image manipulation themselves.

---

## 1. Operation Principles

1. **Operations are declarative.**
2. **Operations are composable.**
3. **Operations execute in deterministic order.**
4. **Operations do not write files.**
5. **Operations do not upload to storage.**
6. **Operations do not generate URLs.**
7. **Operations do not choose encoders or optimizers.**
8. **Operations do not know about Twig, CLI, queues, or Control Panel.**
9. **Operations do not know about Asset Fields or Volumes.**
10. **Operations participate in generation identity.**
11. **Unsupported operations must fail clearly or use an explicit fallback.**
12. **Custom operations must be registerable without modifying core.**

---

## 2. Role in the Pipeline

The Generation Service orchestrates operations:

```text
1. Resolve source
2. Resolve configuration
3. Build GenerationDefinition
4. Expand Profile/Variant into Operation list
5. Select Image Driver
6. Load source image
7. Apply operations in order
8. Encode
9. Optimize
10. Validate
11. Store
```

Operations sit entirely inside step 7.

They must remain independent of steps 8–11.

---

## 3. Operation Contract

Conceptually:

```php
interface OperationInterface
{
    public function name(): string;

    /**
     * Normalized, deterministic options used for execution and identity.
     */
    public function options(): array;

    /**
     * Whether this operation can run on the given driver/image.
     */
    public function supports(Image $image, ImageDriverInterface $driver): bool;

    /**
     * Apply the operation through the selected driver.
     */
    public function apply(Image $image, ImageDriverInterface $driver): Image;
}
```

Exact method signatures may evolve during implementation.

The responsibility boundary must not:

- an operation transforms image data;
- it does not persist, deliver, or encode.

---

## 4. Operation Value Object vs Executable Operation

Prefer separating:

```text
OperationDefinition
    ↓
normalized declarative description

Operation
    ↓
executable application of that definition
```

Example definition:

```php
[
    'name' => 'resize',
    'width' => 1200,
    'height' => null,
    'mode' => 'fit',
]
```

The definition participates in:

- configuration normalization;
- generation identity;
- Playground display;
- CLI dry-run manifests;
- diagnostics.

The executable operation uses the definition plus the selected driver.

---

## 5. Operation Registry

Operations are discovered through:

```text
OperationRegistry
```

Responsibilities:

- register built-in operations;
- register third-party operations;
- resolve operation names to implementations;
- expose available operations for diagnostics/CP;
- reject unknown operation names clearly.

Example built-in names:

```text
resize
crop
fit
fill
scale
rotate
flip
sharpen
blur
brightness
contrast
saturation
grayscale
sepia
invert
gamma
colorize
background
padding
border
watermark
overlay
text
```

Unknown operation names must fail during configuration validation or generation definition building, not silently disappear.

---

## 6. Deterministic Ordering

Operations execute in the order produced by the normalized Generation Definition.

Example:

```text
resize
  ↓
crop
  ↓
brightness
  ↓
sharpen
  ↓
watermark
```

Do not invent implicit reordering unless an operation has an explicit documented constraint and the reordering is itself deterministic and identity-aware.

Preferred rule:

```text
configured order = execution order
```

If the system later introduces automatic operation planning, the planner output must be:

- deterministic;
- visible in dry-run/diagnostics;
- included in generation identity.

---

## 7. Geometry Operations

### 7.1 Resize

Resize changes dimensions according to mode.

Common options:

```text
width
height
mode
upscale
```

Modes conceptually include:

```text
fit
fill
stretch
```

Exact naming may align with Craft conventions where useful, but Super Images owns its own normalized vocabulary.

Rules:

- if only width is provided, preserve aspect ratio unless stretch is requested;
- if only height is provided, preserve aspect ratio unless stretch is requested;
- upscaling must be configurable and default conservatively.

### 7.2 Crop

Crop extracts a region.

Common options:

```text
width
height
position
x
y
```

Position values:

```text
top-left
top-center
top-right
center-left
center
center-right
bottom-left
bottom-center
bottom-right
focal-point
```

Focal-point crop is a first-class Phase 1 capability when the source Asset provides a focal point.

Example:

```text
crop:
  width: 1200
  height: 600
  position: focal-point
```

If focal point is unavailable, fall back to a documented default such as `center`, or fail if configured as required.

### 7.3 Fit

Fit scales the image to fit within a bounding box without cropping.

```text
fit:
  width: 1200
  height: 800
```

### 7.4 Fill

Fill scales and crops as needed to completely fill a target box.

```text
fill:
  width: 1200
  height: 800
  position: center
```

### 7.5 Scale

Scale changes size by a factor.

```text
scale:
  factor: 0.5
```

or:

```text
scale:
  widthFactor: 0.5
  heightFactor: 0.5
```

### 7.6 Rotate

```text
rotate:
  degrees: 90
  background: transparent|#ffffff
```

Rotation must preserve alpha where the format/driver supports it.

### 7.7 Flip

```text
flip:
  direction: horizontal|vertical|both
```

---

## 8. Color Operations

### 8.1 Brightness

```text
brightness:
  amount: 5
```

### 8.2 Contrast

```text
contrast:
  amount: 10
```

### 8.3 Saturation

```text
saturation:
  amount: -5
```

### 8.4 Grayscale

```text
grayscale: true
```

or:

```text
grayscale:
  enabled: true
```

### 8.5 Sepia

```text
sepia:
  amount: 80
```

### 8.6 Invert

```text
invert: true
```

### 8.7 Gamma

```text
gamma:
  value: 1.2
```

Only available where the selected driver supports it.

### 8.8 Colorize

```text
colorize:
  color: '#336699'
  amount: 40
```

Only available where the selected driver supports it.

---

## 9. Effects

### 9.1 Sharpen

```text
sharpen:
  amount: 15
```

Optional advanced options where supported:

```text
radius
sigma
threshold
```

### 9.2 Blur

```text
blur:
  amount: 5
```

### 9.3 Gaussian Blur

Where supported as a distinct operation:

```text
gaussianBlur:
  radius: 3
  sigma: 1.5
```

If a driver cannot distinguish blur vs gaussian blur, map explicitly and document the mapping.

Do not silently drop the effect.

---

## 10. Composition Operations

### 10.1 Background

Useful when flattening transparency or after rotation/padding.

```text
background:
  color: '#ffffff'
```

### 10.2 Padding

```text
padding:
  top: 20
  right: 20
  bottom: 20
  left: 20
  color: '#ffffff'
```

Shorthand may be supported in config and normalized into explicit values.

### 10.3 Border

```text
border:
  width: 4
  color: '#111111'
```

### 10.4 Watermark

Watermark is a first-class composition operation.

```text
watermark:
  source: 'logo'          # asset ID, handle, path policy, or registered watermark
  position: 'bottom-right'
  opacity: 70
  padding: 30
  scale: 0.15
```

Requirements:

- support opacity;
- support position;
- support padding;
- support relative scale;
- support absolute width/height where useful;
- never accept arbitrary remote URLs from untrusted runtime input;
- resolve watermark sources through a controlled source policy.

Watermark source resolution belongs to application/infrastructure services, not to Twig filters.

### 10.5 Image Overlay

More general than watermark:

```text
overlay:
  source: 'badge'
  x: 40
  y: 40
  opacity: 100
  width: 120
```

### 10.6 Text Overlay

Where supported:

```text
text:
  content: 'Sale'
  font: 'Inter'
  size: 48
  color: '#ffffff'
  position: 'bottom-left'
  padding: 24
  opacity: 90
```

Text rendering support varies by driver.

If unsupported:

- fail clearly when explicitly requested;
- or skip only when an explicit fallback policy says so.

Never pretend text was rendered.

---

## 11. Focal Point Integration

Craft Assets may include focal point data.

Super Images should treat focal point as a geometry input:

```text
Asset focal point
      ↓
crop/fill position = focal-point
      ↓
driver crop around focal coordinates
```

Rules:

- focal point coordinates must be normalized;
- missing focal point has a documented fallback;
- focal point participates in generation identity when used;
- changing focal point must be capable of invalidating related derivatives.

---

## 12. Profile / Variant Expansion into Operations

Profiles and variants describe intent.

The configuration/generation layer expands them into concrete operations.

Example profile:

```php
'hero' => [
    'variants' => [
        'desktop' => [
            'width' => 1920,
            'height' => 1080,
            'mode' => 'crop',
            'position' => 'focal-point',
        ],
    ],
    'defaults' => [
        'sharpen' => 10,
        'watermark' => 'logo',
    ],
    'formats' => ['webp', 'avif', 'jpg'],
]
```

Expanded operations might become:

```text
1. fill/crop to 1920×1080 using focal-point
2. sharpen 10
3. watermark logo
```

Exact expansion rules belong with configuration normalization, but operations must consume the resulting normalized list.

---

## 13. Runtime Custom Operations

Phase 2 may allow runtime overrides such as:

```twig
{{ asset|generateUrl('webp', {
    width: 1200,
    sharpen: 12
}) }}
```

Phase 1 must ensure:

- operation definitions can be built from structured options;
- options are validated;
- options are normalized;
- options participate in identity;
- unsafe options cannot become shell commands or filesystem paths.

Phase 1 does not implement the Twig API.

---

## 14. Driver Capability Interaction

Before applying an operation:

```text
Operation
   ↓
Driver.supports(operation)
   ↓
yes → apply
no  → explicit fallback or hard failure
```

Silent no-ops are forbidden.

Example:

```text
Requested: colorize
Driver: GD without support
Result: InvalidOperationException or configured fallback
```

Capability information must be available to diagnostics and later Control Panel UI.

---

## 15. Alpha Channel and Transparency

Operations must preserve alpha when:

- the current image has alpha;
- the selected output format supports alpha;
- the operation does not explicitly flatten.

Flattening must be intentional, for example via background composition before JPEG encoding.

JPEG encoding of transparent sources should follow a documented policy such as:

```text
transparent source
   ↓
flatten onto configured background
   ↓
JPEG encode
```

That flattening may be represented as an explicit operation or an encoder precondition, but it must be deterministic and identity-aware.

---

## 16. Animation Policy

Animated GIF/WebP sources are a special case.

Phase 1 should define a clear policy:

```text
Option A: process first frame only
Option B: reject animated sources for unsupported pipelines
Option C: preserve animation where driver/format support exists
```

Recommended initial policy:

- detect animation;
- default to first-frame processing unless explicitly configured otherwise;
- never silently produce a broken multi-frame output;
- include animation handling choice in generation identity when it affects output.

---

## 17. Metadata Policy During Operations

Operations may need orientation/metadata awareness.

Example:

```text
EXIF orientation
   ↓
normalize orientation before geometry operations
```

Recommended behavior:

- auto-orient early in the pipeline when source metadata indicates rotation;
- treat auto-orient as an explicit deterministic step;
- strip or preserve metadata according to encoder/optimizer policy later.

Do not leave orientation handling implicit and driver-dependent without normalization.

---

## 18. Operation Options Normalization

All operation options must be normalized before identity calculation and execution.

Examples:

```text
position: 'center-center' → 'center'
opacity: '70%' → 70
scale: 15% → 0.15
color: 'fff' → '#ffffff'
padding: 20 → {top:20,right:20,bottom:20,left:20}
```

Normalization rules:

- deterministic;
- lossless with respect to meaning;
- documented;
- shared by config, Twig overrides, CLI, and Playground.

Unnormalizable values must fail validation.

---

## 19. Generation Identity Participation

Operations are part of generation identity.

```text
Generation Identity includes:
- operation names
- normalized operation options
- operation order
- schema/version affecting operation semantics
```

Therefore:

```text
same source + same operations
        =
same identity

sharpen:10 → sharpen:12
        =
new identity
```

Secrets, temporary paths, and storage credentials must never appear in operation identity input.

---

## 20. Error Handling

Domain exceptions should include:

```text
InvalidOperationException
UnsupportedOperationException
OperationValidationException
WatermarkSourceException
```

Errors must include enough context for CLI/Playground diagnostics:

- operation name;
- relevant normalized options;
- driver name;
- asset identity if available;

They must not include:

- storage secrets;
- signed URL tokens;
- raw credentials;
- unnecessary absolute local temp paths in user-facing messages.

---

## 21. Security Rules

Operations are not a free-form scripting engine.

Especially for runtime overrides:

- allow only known operation names;
- validate option types and ranges;
- reject arbitrary filesystem paths;
- reject arbitrary remote URLs unless explicitly allow-listed by trusted config;
- reject unbounded dimensions / pixel counts via resource limits from Phase 2 runtime policy;
- never construct shell commands from operation options.

Watermark/overlay sources must resolve through controlled resolvers.

---

## 22. Extension API for Custom Operations

Third-party plugins should eventually register operations:

```text
Custom plugin
     ↓
OperationRegistry::register()
     ↓
available to config/Twig/CLI/Playground
```

A custom operation must:

- expose a stable name;
- validate options;
- declare driver requirements/capabilities;
- apply through the selected driver or its own safe implementation boundary;
- participate cleanly in identity.

Custom operations must not bypass storage, encoding, or generation services.

---

## 23. Suggested Class Structure

Conceptual structure:

```text
src/operations/
├── OperationInterface.php
├── OperationDefinition.php
├── OperationRegistry.php
├── OperationPipeline.php
├── geometry/
│   ├── ResizeOperation.php
│   ├── CropOperation.php
│   ├── FitOperation.php
│   ├── FillOperation.php
│   ├── ScaleOperation.php
│   ├── RotateOperation.php
│   └── FlipOperation.php
├── color/
│   ├── BrightnessOperation.php
│   ├── ContrastOperation.php
│   ├── SaturationOperation.php
│   ├── GrayscaleOperation.php
│   ├── SepiaOperation.php
│   ├── InvertOperation.php
│   ├── GammaOperation.php
│   └── ColorizeOperation.php
├── effects/
│   ├── SharpenOperation.php
│   ├── BlurOperation.php
│   └── GaussianBlurOperation.php
└── composition/
    ├── BackgroundOperation.php
    ├── PaddingOperation.php
    ├── BorderOperation.php
    ├── WatermarkOperation.php
    ├── OverlayOperation.php
    └── TextOperation.php
```

Exact filenames may follow Craft conventions. Boundaries matter more than folder taste.

---

## 24. Operation Pipeline Service

A dedicated pipeline helper is useful:

```text
OperationPipeline
```

Responsibilities:

- accept normalized operation definitions;
- resolve them through the registry;
- validate support against the selected driver;
- apply them sequentially;
- return the final image representation.

Example flow:

```text
Image
  + OperationDefinitions
  + Driver
        ↓
OperationPipeline
        ↓
Transformed Image
```

The Generation Service should call this rather than applying operations ad hoc.

---

## 25. Interaction with Encoders

Operations end before encoding.

Do not:

```text
ResizeOperation → write WebP file
```

Do:

```text
ResizeOperation → Image
Encoder → EncodedImage
```

Format-specific concerns such as progressive JPEG or AVIF speed mode belong to encoders/optimizers, not operations.

---

## 26. Interaction with Storage

Operations never store.

Even watermark source loading is source resolution, not derivative storage.

Derivative persistence happens only through Storage adapters after validation.

---

## 27. Testing Requirements

Phase 1 operation tests must cover:

### Geometry

- resize by width only
- resize by height only
- fit
- fill
- crop positions
- focal-point crop
- rotate
- flip
- no-upscale behavior

### Color / Effects

- brightness
- contrast
- saturation
- grayscale
- sepia
- invert
- sharpen
- blur

### Composition

- background flatten
- padding
- border
- watermark position/opacity/scale
- overlay placement

### Pipeline

- deterministic ordering
- identity changes when options change
- unsupported operation failure
- normalization of shorthand options
- alpha preservation where expected
- orientation normalization

### Drivers

- same operation definitions against Libvips/Imagick/GD where available
- capability rejection paths

Use fixture images with known dimensions and visual/byte assertions where practical.

Prefer structural assertions (dimensions, format readiness, alpha presence) over brittle exact binary equality across drivers.

---

## 28. Performance Requirements

Operations should:

- avoid decoding the source repeatedly;
- avoid unnecessary image clones;
- release intermediate resources when safe;
- not load watermark/overlay sources more times than needed within one generation;
- not perform network I/O except through controlled source resolvers;
- not touch storage adapters.

For large images, prefer driver-native operations over PHP-level pixel loops.

---

## 29. Future Operations (Non-Goals for Phase 1 Core Completeness)

These may be designed for extension later, but are not required to finish Phase 1:

```text
smart crop / face-aware crop
background removal
object detection guided crop
perspective transforms
pixelate
noise
emboss
rounded corners as a dedicated op
```

If introduced later, they must register through the same OperationRegistry and identity model.

---

## 30. Architectural Invariants

1. There is one operation model for all integrations.
2. Operations do not encode.
3. Operations do not optimize.
4. Operations do not store.
5. Operations do not generate URLs.
6. Operation order is deterministic.
7. Operation options are normalized before identity and execution.
8. Unsupported operations never fail silently.
9. Focal-point crop is supported through normalized geometry options.
10. Custom operations plug into the registry rather than forking the pipeline.

---

## 31. Definition of Done

Operations are complete for Phase 1 when:

- [ ] OperationInterface / definitions exist
- [ ] OperationRegistry exists
- [ ] OperationPipeline exists
- [ ] Geometry operations work
- [ ] Color operations work
- [ ] Effect operations work
- [ ] Watermark/overlay works
- [ ] Focal-point crop works with fallback policy
- [ ] Normalization is deterministic
- [ ] Identity includes operations
- [ ] Driver capability checks work
- [ ] Custom operation registration path exists
- [ ] Tests cover core operations and ordering
- [ ] No operation writes permanent storage

---

## Final Rule

**Operations describe image intent. Drivers execute that intent. Nothing else should reinvent image manipulation.**

If CLI, Twig, Playground, or runtime URLs need a new visual effect, they should add or reuse an operation — not bypass the pipeline.
