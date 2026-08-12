# Phase 1 — Configuration

## Purpose

This document defines the configuration architecture for **Super Images**.

Configuration is a core part of the image-generation engine because the same Asset may be affected by global defaults, a volume, a folder, an Asset field, a profile, a variant, a format, encoder settings, optimizer settings, and storage settings.

The configuration system must provide one deterministic way to resolve all of these settings.

The same resolved configuration must be used by:

- CLI
- Queue
- Runtime generation
- Twig
- Control Panel Playground
- Future APIs

There must never be separate configuration-merging logic for each integration.

---

## 1. Configuration Principles

The configuration system must follow these principles:

1. **One configuration model**
2. **One configuration resolver**
3. **Deterministic precedence**
4. **PHP config support**
5. **Control Panel settings support**
6. **No duplicated configuration merging**
7. **Minimal database queries**
8. **Normalized configuration before generation**
9. **Configuration must be cacheable**
10. **Secrets must never participate in generation identity**
11. **Configuration must be extensible**
12. **Configuration changes must be capable of invalidating generated derivatives**

---

## 2. Configuration Sources

Super Images supports two primary configuration sources.

### 2.1 PHP Configuration

The plugin must support:

```text
config/super-images.php
```

This is intended for version-controlled configuration, deployments, CI/CD, advanced developers, and repeatable environments.

Example:

```php
<?php

return [
    'profiles' => [
        'responsive' => [
            'formats' => ['jpg', 'webp', 'avif'],
            'transforms' => [
                ['width' => 576],
                ['width' => 768],
                ['width' => 992],
                ['width' => 1280],
                ['width' => 1600],
            ],
            'defaults' => [
                'position' => 'center-center',
                'mode' => 'crop',
                'jpegQuality' => 80,
            ],
        ],
    ],

    'volumes' => [
        'images' => [
            'profile' => 'responsive',
        ],
    ],
];
```

The exact final schema can be refined during implementation, but PHP configuration is a first-class configuration source, not a secondary feature.

### 2.2 Control Panel Configuration

The plugin must eventually allow configuration through the Control Panel.

Control Panel configuration and PHP configuration must resolve into the **same internal configuration model**.

```text
PHP Config ───────┐
                  ├──> Configuration Providers ──> ConfigurationResolver
CP Config ────────┘
```

The image-generation engine must not care where the configuration originated.

---

## 3. Configuration Scope and Precedence

The supported scope hierarchy is:

```text
General
  ↓
Volume
  ↓
Folder
  ↓
Asset Field
```

Therefore:

```text
Asset Field > Folder > Volume > General
```

The most specific applicable configuration wins.

This precedence is a core architectural decision and must be identical in CLI, Twig, queue, Playground, and runtime generation.

### Important Asset Field rule

An Asset Field can allow Assets from multiple volumes. Field configuration therefore does **not** replace volume configuration blindly.

The resolver must use the actual Asset context:

```text
General
  ↓
Asset's Volume
  ↓
Asset's Folder
  ↓
Requesting Asset Field
```

The resolver must know the actual Asset and, where relevant, the requesting Asset Field context.

---

## 4. Configuration Resolver

The canonical service is conceptually:

```text
ConfigurationResolver
```

Example:

```php
$config = $configurationResolver->resolve(
    asset: $asset,
    field: $field,
    profile: $profile,
);
```

The exact method signature may be refined during implementation. The important requirement is that **all consumers use the same resolver**.

---

## 5. Resolution Pipeline

The effective configuration should conceptually be resolved as follows:

```text
Load plugin configuration
        ↓
Apply General settings
        ↓
Resolve Asset volume
        ↓
Apply Volume settings
        ↓
Resolve Asset folder
        ↓
Apply Folder settings
        ↓
Resolve Asset Field context
        ↓
Apply Asset Field settings
        ↓
Resolve selected Profile
        ↓
Apply profile defaults
        ↓
Apply variant settings
        ↓
Apply format settings
        ↓
Normalize
        ↓
EffectiveConfiguration
```

The exact property-level precedence between profile, variant, format, and scope values must be encoded in one resolver and never duplicated elsewhere.

---

## 6. Effective Configuration

The resolver returns a normalized representation such as:

```text
EffectiveConfiguration
├── driver
├── source
├── profile
├── variant
├── transforms
├── operations
├── formats
├── encoder
├── optimizer
├── storage
└── runtime settings
```

Consumers should not repeatedly merge raw arrays.

---

## 7. Configuration Schema

The top-level PHP configuration should conceptually support:

```php
return [
    'general' => [],
    'profiles' => [],
    'volumes' => [],
    'folders' => [],
    'fields' => [],
    'storage' => [],
    'drivers' => [],
    'encoders' => [],
    'optimizers' => [],
];
```

Not every key must exist in every configuration file. Missing values inherit from lower scopes or plugin defaults.

The exact schema may evolve, but the scope model must remain stable.

---

## 8. Profiles

Profiles are reusable generation definitions.

Example:

```php
'profiles' => [
    'responsive' => [
        'formats' => ['jpg', 'webp', 'avif'],
        'transforms' => [
            ['width' => 576],
            ['width' => 768],
            ['width' => 992],
            ['width' => 1280],
            ['width' => 1600],
        ],
        'defaults' => [
            'position' => 'center-center',
            'mode' => 'crop',
            'jpegQuality' => 80,
        ],
        'configOverrides' => [
            'fillTransforms' => true,
            'fillInterval' => 300,
        ],
    ],
],
```

Profiles should be reusable from volume, folder, and Asset Field configuration, as well as CLI, Twig, and Playground.

---

## 9. Profile Overrides

A scope may select a profile and override specific settings:

```php
'volumes' => [
    'images' => [
        'profile' => 'responsive',
        'defaults' => [
            'jpegQuality' => 75,
        ],
    ],
],
```

The profile is the baseline. Scope-specific values are applied afterward according to the defined precedence rules.

---

## 10. Variants

Variants represent individual generated sizes or transformation definitions.

Example:

```php
'variants' => [
    'sm' => ['width' => 576],
    'md' => ['width' => 768],
    'lg' => ['width' => 1280],
],
```

Variants must not contain storage-specific information.

---

## 11. Formats

Formats are first-class configuration.

Initial formats:

```text
jpg
jpeg
png
webp
avif
```

The architecture must allow additional formats later.

A format definition is conceptually more than an extension:

```text
Format
├── name
├── extension
├── MIME type
├── encoder
├── default quality
├── optimization strategy
└── capabilities
```

This permits multiple encoder implementations for the same format.

---

## 12. Defaults and Overrides

Defaults are applied before more specific overrides.

Conceptually:

```text
Plugin Defaults
       ↓
General Defaults
       ↓
Profile Defaults
       ↓
Volume Defaults
       ↓
Folder Defaults
       ↓
Field Defaults
       ↓
Variant/Format-specific settings
```

The final implementation must encode property-level precedence centrally.

---

## 13. Transform Configuration

Transform configuration may define multiple variants:

```php
' transforms' => [
    ['width' => 576],
    ['width' => 768],
    ['width' => 992],
    ['width' => 1280],
],
```

The implementation should support a normalized operation representation for more complex transformations:

```php
'operations' => [
    [
        'type' => 'resize',
        'width' => 1280,
    ],
    [
        'type' => 'sharpen',
        'amount' => 10,
    ],
],
```

The operation system itself is defined in `operations.md`.

---

## 14. Auto-Generation Configuration

Profiles define the formats and variants that CLI/queue generation should know about.

Example:

```php
'profiles' => [
    'responsive' => [
        'formats' => ['jpg', 'webp'],
        'transforms' => [
            ['width' => 576],
            ['width' => 768],
            ['width' => 992],
            ['width' => 1280],
            ['width' => 1600],
        ],
    ],
],
```

This is the source of truth for what the generation manifest should contain.

A developer should be able to inspect `config/super-images.php` and understand what the CLI is expected to generate.

---

## 15. Manifest Relationship

Phase 2 will convert effective configuration into a generation manifest:

```text
EffectiveConfiguration
        ↓
Manifest Builder
        ↓
Generation Manifest
        ↓
CLI / Queue
```

The same Asset/context must produce the same manifest when configuration has not changed.

---

## 16. Configuration Validation

Configuration must be validated before generation.

Validate at minimum:

- profile names;
- variant names;
- format names;
- dimensions;
- quality ranges;
- operation names;
- driver names;
- encoder names;
- optimizer names;
- storage adapter names;
- incompatible combinations.

Invalid configuration must fail clearly rather than reaching the image driver.

Unknown configuration keys must not silently become arbitrary engine behavior.

---

## 17. Configuration Normalization

Raw configuration may contain:

- aliases;
- defaults;
- shorthand values;
- inherited settings;
- optional keys;
- provider-specific settings.

These must be normalized before entering the generation pipeline.

For example:

```php
['width' => 768]
```

may become internally:

```php
[
    'operation' => 'resize',
    'width' => 768,
    'height' => null,
    'mode' => 'fit',
]
```

The normalized representation must be stable and predictable.

---

## 18. Typed Internal Configuration

PHP configuration may be represented as arrays at the boundary, but the internal representation should use typed value objects/models where practical:

```text
ProfileDefinition
VariantDefinition
FormatDefinition
EncoderDefinition
OptimizerDefinition
StorageDefinition
EffectiveConfiguration
```

Do not pass arbitrary nested arrays through every service.

---

## 19. Lists vs Maps

Configuration must distinguish between lists and associative maps.

List:

```php
'formats' => ['jpg', 'webp', 'avif']
```

Map:

```php
'profiles' => [
    'responsive' => [...],
    'thumbnail' => [...],
]
```

The resolver must not accidentally merge lists as associative arrays.

---

## 20. Explicit Merge Semantics

Do not rely on generic recursive array merging for business rules.

For example, blindly using:

```php
array_merge_recursive()
```

is prohibited for effective configuration resolution.

Merge behavior must be defined by schema.

Typical rules:

```text
scalar value       → replace
associative map    → merge by key
operation list     → explicit replace/append semantics
format list        → explicit override semantics
```

The behavior must be deterministic and tested.

---

## 21. Configuration Caching

Configuration resolution is performance-sensitive.

Normalized configuration should be cached where safe.

Potential cache dimensions include:

```text
site
volume
folder
field
profile
configuration version
```

Do not cache context-dependent values without including the relevant context in the cache key.

---

## 22. Cache Invalidation

Configuration changes must invalidate cached effective configuration.

Use a configuration revision/version concept:

```text
Configuration
     ↓
revision = X
     ↓
cache key
```

After configuration changes:

```text
revision = Y
```

Old configuration entries are no longer used.

---

## 23. Database Query Discipline

Configuration resolution must be query-sparing.

Incorrect:

```text
Asset
 ↓
query volume
 ↓
query folder
 ↓
query field
 ↓
generate variant
 ↓
repeat queries for every variant
```

Correct:

```text
Resolve Asset context
       ↓
Resolve configuration once
       ↓
Generate all variants from memory
```

Bulk generation must not repeatedly resolve the same configuration.

---

## 24. Configuration and Generation Identity

Only output-affecting configuration belongs in generation identity.

Examples that affect identity:

```text
width
height
crop mode
crop position
quality
format
encoder options
optimizer behavior when output changes
operations
watermark configuration
processing version
```

Examples that do not:

```text
AWS secret key
AWS access key
Control Panel UI state
CLI verbosity
log level
```

Storage credentials must never be part of image content identity.

---

## 25. Storage Namespace vs Content Identity

Storage location and image content identity should remain conceptually separate.

```text
Content Identity
+
Storage Namespace
=
Final Storage Location
```

Changing credentials must not invalidate every image. A change in storage namespace/path can affect the final location without changing the image content identity.

---

## 26. Runtime Configuration

Persistent project configuration and runtime request settings are separate concepts.

Project configuration describes:

```text
profiles
formats
variants
operations
storage adapters
driver preferences
```

Runtime input may describe:

```text
Asset
requested format
requested variant
approved runtime-safe overrides
```

Runtime input must not mutate persistent configuration.

Phase 2 defines the public runtime API.

---

## 27. Runtime Overrides

Future runtime generation may permit safe overrides such as:

```text
width
height
format
quality
crop
```

Runtime overrides must:

- be explicitly allowed;
- be validated;
- be bounded;
- not mutate persistent configuration;
- produce a distinct generation identity;
- not allow arbitrary operations unless explicitly enabled.

---

## 28. Configuration Security

Never allow runtime configuration to select:

```text
arbitrary filesystem paths
arbitrary shell commands
arbitrary binaries
arbitrary PHP classes
arbitrary storage adapters
```

Only capabilities explicitly exposed by Super Images may be selected.

---

## 29. Environment Configuration

`config/super-images.php` may use Craft environment configuration patterns.

Example:

```php
'storage' => [
    'driver' => App::env('SUPER_IMAGES_STORAGE'),
],
```

Credentials should remain environment-backed where appropriate.

Do not require secrets to be committed to source control.

---

## 30. PHP Config and Control Panel Ownership

The plugin must establish clear ownership for settings.

Recommended model:

```text
PHP Config
    ↓
baseline/developer configuration

Control Panel
    ↓
site-specific configuration
```

Both are normalized by the same resolver.

If a setting is managed through the Control Panel, administrators must be able to determine its effective value.

---

## 31. Configuration Extensions

Third-party plugins may eventually register:

- custom profiles;
- custom operations;
- custom encoders;
- custom optimizers;
- custom storage adapters;
- additional configuration fields.

Extensions must pass through the same validation and normalization process.

Potential extension events include:

```text
RegisterProfiles
RegisterFormats
RegisterOperations
RegisterEncoders
RegisterOptimizers
RegisterStorageAdapters
ModifyConfiguration
```

Events are extension points, not a replacement for ConfigurationResolver.

---

## 32. Configuration Diagnostics

The architecture must support a future diagnostic view showing exactly why an effective value was selected.

Example:

```text
jpegQuality

General: 80
Volume: 78
Folder: 75
Field: 72

Effective: 72
```

This is important because inherited configuration must be debuggable.

Phase 3 will provide the Control Panel diagnostics UI.

---

## 33. Configuration and CLI

The CLI must be able to determine what to generate without users manually remembering every transform.

For example:

```bash
php craft super-images/generate --asset=123
```

Flow:

```text
Asset
 ↓
ConfigurationResolver
 ↓
Profile
 ↓
Variants
 ↓
Formats
 ↓
Generation Manifest
```

Configuration is therefore the source of truth for CLI generation.

---

## 34. Configuration and Twig

Future Twig APIs such as:

```twig
{{ asset|generatePictureTag(['jpg', 'webp']) }}

{{ asset|generateImgTag('webp') }}

{{ asset|generateUrl('jpg') }}
```

must use the same ConfigurationResolver.

Twig must not need to know which volume, folder, field, encoder, optimizer, or storage settings apply.

---

## 35. Declarative Configuration

Prefer:

```php
'profile' => 'responsive'
```

over executable callbacks such as:

```php
'callback' => function (...) {
    // custom generation logic
}
```

Configuration describes desired output. Business logic belongs in services/extensions.

---

## 36. Configuration Schema Version

The plugin should maintain an internal configuration schema version separate from the plugin version.

Conceptually:

```text
configurationSchemaVersion
```

A schema change may require:

- configuration migration;
- cache invalidation;
- derivative invalidation;
- compatibility handling.

Do not assume plugin version and configuration schema version are identical.

---

## 37. Backward Compatibility

Once the public configuration schema is released, changes are API changes.

Do not silently change the semantics of existing keys.

If a breaking change is required:

1. document it;
2. provide migration where practical;
3. update schema version;
4. invalidate affected caches/identities;
5. update documentation.

---

## 38. Example Full Configuration

```php
<?php

return [
    'general' => [
        'driver' => 'libvips',
    ],

    'profiles' => [
        'responsive' => [
            'formats' => ['jpg', 'webp', 'avif'],

            'transforms' => [
                ['width' => 576],
                ['width' => 768],
                ['width' => 992],
                ['width' => 1280],
                ['width' => 1600],
            ],

            'defaults' => [
                'position' => 'center-center',
                'mode' => 'crop',
                'jpegQuality' => 80,
            ],

            'configOverrides' => [
                'fillTransforms' => true,
                'fillInterval' => 300,
            ],
        ],
    ],

    'volumes' => [
        'images' => [
            'profile' => 'responsive',
        ],
    ],

    'folders' => [
        'images/products' => [
            'profile' => 'product',
        ],
    ],

    'fields' => [
        'heroImages' => [
            'profile' => 'hero',
        ],
    ],
];
```

The exact final key names can be refined during implementation, but the concepts and precedence must remain stable.

---

## 39. Example Resolution

Given:

```text
General:
    jpegQuality = 80

Volume:
    jpegQuality = 78

Folder:
    jpegQuality = 75

Field:
    jpegQuality = 72
```

The effective value is:

```text
72
```

If the Field has no override:

```text
75
```

If the Folder has no override:

```text
78
```

If neither Folder nor Volume overrides it:

```text
80
```

---

## 40. Configuration Resolution Example

```text
Asset
│
├── Volume: images
│
├── Folder: products
│
└── Field: productImages
        │
        ▼
ConfigurationResolver
        │
        ├── General
        ├── Volume: images
        ├── Folder: products
        └── Field: productImages
                │
                ▼
        Profile: product
                │
                ▼
        Variant: lg
                │
                ▼
        Format: webp
                │
                ▼
        EffectiveConfiguration
```

---

## 41. Architectural Invariants

The following rules are mandatory.

### Invariant 1

There is one ConfigurationResolver.

### Invariant 2

General → Volume → Folder → Asset Field precedence is consistent everywhere.

### Invariant 3

PHP config and Control Panel config resolve into the same internal model.

### Invariant 4

Twig does not merge configuration.

### Invariant 5

CLI does not merge configuration.

### Invariant 6

Queue jobs do not merge configuration.

### Invariant 7

Playground does not create a separate configuration system.

### Invariant 8

Configuration is normalized before reaching the image-processing engine.

### Invariant 9

Configuration resolution is query-sparing.

### Invariant 10

Runtime overrides never mutate persistent configuration.

### Invariant 11

Secrets never participate in generation identity.

### Invariant 12

Configuration changes can invalidate cached configuration and generated identities.

### Invariant 13

The configuration file remains readable and declarative.

### Invariant 14

Configuration semantics must not silently change.

---

# Final Rule

**Configuration is the source of truth for what Super Images should generate.**

The engine determines **how** an image is generated.

The configuration determines **what** should be generated.

The integration layer determines **when** generation happens.

Therefore:

```text
Configuration
      ↓
What to generate
      ↓
Generation Engine
      ↓
How to generate it
      ↓
CLI / Queue / Runtime / Twig / Playground
      ↓
When and where it is requested
```

Keep these responsibilities separate.

That separation is required for Super Images to remain predictable, fast, and extensible as the plugin grows.
