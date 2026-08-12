# Phase 2 — Generation Manifest

## Purpose

The Generation Manifest is the central representation of **what derivatives should exist** for a given Asset and effective configuration.

It connects:

```text
Configuration
     ↓
Profiles / Variants / Formats
     ↓
Generation Manifest
     ↓
CLI / Queue / Runtime / Diagnostics / Playground
```

Without a manifest, every integration invents its own idea of “what to generate.”

That must not happen.

---

## 1. Manifest Principles

1. **One manifest model for all integrations.**
2. **Manifest units map 1:1 to GenerationRequests where practical.**
3. **Manifest expansion is deterministic.**
4. **Dry-run uses the same expansion as real generation.**
5. **Manifest does not process images.**
6. **Manifest does not write storage.**
7. **Manifest does not query a GeneratedImage table.**
8. **Manifest can be listed, counted, filtered, and batched.**

---

## 2. What a Manifest Represents

For:

```text
Profile: responsive
Variants: sm/md/lg/xl/2xl
Formats: jpg, webp, avif
```

the manifest represents:

```text
sm.jpg
sm.webp
sm.avif
md.jpg
md.webp
md.avif
lg.jpg
lg.webp
lg.avif
xl.jpg
xl.webp
xl.avif
2xl.jpg
2xl.webp
2xl.avif
```

Each item is a generation unit.

---

## 3. Manifest Unit

Conceptual value object:

```text
ManifestUnit
├── Asset identity
├── Profile
├── Variant
├── Format
├── Normalized operations/options
├── Encoder options
├── Optimizer options
├── Storage target
├── Generation identity
└── Deterministic path / URL preview
```

A unit must contain enough data to:

- create a GenerationRequest;
- calculate identity/path;
- display in CLI dry-run;
- enqueue a job;
- support diagnostics.

It must not contain raw secrets.

---

## 4. Manifest Builder

```text
ManifestBuilder / ManifestService
```

Responsibilities:

- accept Asset + optional field/profile context;
- resolve effective configuration via ConfigurationResolver;
- expand profiles into variants × formats;
- apply includes/excludes/filters;
- produce Manifest / ManifestUnit collection;
- remain side-effect free.

Pseudo-flow:

```text
Asset
 ↓
ConfigurationResolver
 ↓
effective profiles
 ↓
for each profile
   for each variant
      for each format
         build ManifestUnit
 ↓
Manifest
```

---

## 5. Expansion Rules

### 5.1 Profile expansion

A profile with variants and formats expands to:

```text
count(variants) × count(formats)
```

units per profile, unless filters reduce it.

### 5.2 Multiple profiles

If an Asset/field resolves multiple profiles:

```text
profile A units
+
profile B units
```

Deduplicate only when generation identity/path would be identical.

### 5.3 Defaults

Variant-level settings override profile defaults.

Normalized operations must already be resolved before unit finalization.

### 5.4 Runtime single-unit manifests

Runtime generation may create a manifest of one unit from signed parameters.

That still uses the same ManifestUnit model.

---

## 6. Filtering

Manifest building must support filters used by CLI/queue:

```text
--asset=123
--volume=images
--folder=...
--profile=responsive
--variant=md
--format=webp
--field=heroImage
```

Filters happen during expansion/selection, not by processing everything and discarding results late when avoidable.

---

## 7. Dry-Run Support

Dry-run must use the real manifest builder.

Example CLI output concepts:

```text
Asset #123 hero.jpg
  responsive/sm.webp   → derivatives/.../....webp
  responsive/sm.avif   → derivatives/.../....avif
  responsive/md.webp   → derivatives/.../....webp
  ...
```

Dry-run may include:

- planned path;
- planned public URL;
- identity;
- format;
- dimensions intent;
- whether an exists() check was requested (optional explicit mode only).

Dry-run must not generate images.

A separate `--check-exists` mode may call storage exists() for diagnostics, but that is not the default dry-run and must never be used by Twig rendering.

---

## 8. Batching

Large libraries need batch-friendly manifests.

Do not:

```text
load 1,000,000 assets into memory
build full giant manifest
```

Do:

```text
query assets in pages/batches
build manifest per batch
enqueue/process batch
release memory
```

ManifestService should support generators/iterators or explicit batch APIs.

---

## 9. Relationship to GenerationService

```text
ManifestUnit
   ↓
GenerationRequest
   ↓
GenerationService::generate()
   ↓
GenerationResult
```

The manifest layer prepares work.

The generation service executes work.

Keep them separate.

---

## 10. Eager Generation Strategy

Eager flows:

### On demand CLI

```text
php craft super-images/generate --volume=images
```

### On Asset save (optional configurable behavior)

```text
Asset saved
  ↓
build manifest for relevant profiles
  ↓
enqueue jobs
```

Asset-save eager generation must be configurable and safe for large uploads.

Default should likely enqueue rather than synchronously generate huge AVIF sets during HTTP requests.

---

## 11. Lazy Generation Strategy

Lazy flows do not prebuild all units.

They still understand units:

```text
Twig needs responsive/md.webp URL
  ↓
deterministic URL or signed runtime URL for that unit
  ↓
if missing at request time, runtime generates that unit
```

Manifest concepts remain useful for:

- knowing which units a picture tag expects;
- diagnostics;
- later cleanup of obsolete units.

---

## 12. Determinism

Given the same:

```text
Asset version/identity
effective config
profile
variant
format
processing schema version
```

manifest expansion must produce the same units and identities.

No random ordering for identity-sensitive outputs.

Listing order may be stable-sorted for CLI readability.

---

## 13. No Database Manifest Table Requirement

The manifest is a computed structure.

It is not stored as one DB row per derivative.

Optional future caches for diagnostics are allowed only if they do not become the source of truth and do not affect normal frontend rendering.

---

## 14. Error Handling

Manifest building errors should fail early:

- unknown profile;
- invalid variant;
- unsupported format;
- invalid operation options;
- unresolved storage adapter;

These should surface clearly in CLI dry-run and queue job setup.

---

## 15. Testing Requirements

- expands variants × formats correctly
- multiple profiles
- filters
- deterministic identities
- dedupe identical units
- batch iteration behavior
- dry-run has no generation side effects
- runtime single-unit construction
- invalid config fails clearly

---

## 16. Definition of Done

- [ ] ManifestUnit model exists
- [ ] ManifestBuilder/Service exists
- [ ] Profile/variant/format expansion works
- [ ] Filters work
- [ ] Dry-run listing works
- [ ] Batch APIs work for large volumes
- [ ] Units convert cleanly to GenerationRequests
- [ ] No GeneratedImage table introduced
- [ ] Tests cover expansion and determinism

---

## Final Rule

**If CLI, queue, runtime, Twig, and Playground disagree about what should be generated, the manifest design has failed.**

Build one expansion model and make every integration consume it.
