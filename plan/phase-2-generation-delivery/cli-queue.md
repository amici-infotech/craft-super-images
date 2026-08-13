# Phase 2 — CLI & Queue

## Purpose

This document defines eager generation through Craft console commands and Craft queue jobs.

CLI and queue are orchestration layers.

They must call:

```text
Manifest Service
      ↓
Generation Service (Phase 1)
```

They must not implement image processing, encoding, optimization, or storage writes themselves.

---

## 1. Goals

- generate derivatives for one Asset, many Assets, a volume, or a profile;
- support dry-run;
- support queue offloading;
- remain memory-safe for large libraries;
- provide useful progress and failure reporting;
- remain idempotent.

---

## 2. Primary Commands

Conceptual command set:

```bash
php craft super-images/generate
php craft super-images/generate --asset=123
php craft super-images/generate --volume=images
php craft super-images/generate --profile=responsive
php craft super-images/generate --format=webp
php craft super-images/generate --dry-run
php craft super-images/generate --queue=1

php craft super-images/config
php craft super-images/config --asset=123

php craft super-images/status
```

Exact Craft command naming may follow plugin handle conventions, for example:

```text
super-images/generate
```

Capabilities matter more than final route spelling.

---

## 3. Generate Command Behavior

### 3.1 Selection

Resolve target Assets using filters:

```text
asset
volume
folder
section/entry context if explicitly supported later
profile
variant
format
field
```

### 3.2 Expansion

For each Asset:

```text
build manifest
  ↓
optionally filter units
  ↓
generate or enqueue each unit
```

### 3.3 Dry-run

`--dry-run` lists planned units and paths without generation.

### 3.4 Queue mode

`--queue` enqueues jobs instead of generating inline.

Default behavior should be configurable.

For large selections, queue mode should be preferred.

---

## 4. Idempotency

Generating an already-up-to-date deterministic derivative should be safe.

Possible strategies:

1. always regenerate and overwrite;
2. check exists() and skip;
3. check exists() plus optional metadata/policy.

Recommended default for eager CLI:

```text
skip if object exists for current identity/path
unless --force
```

`--force` regenerates even if present.

Because identity changes when config changes, new configs naturally create new paths and do not require mutating old objects in place.

---

## 5. Queue Job Design

Use Craft’s queue.

Conceptual jobs:

```text
GenerateDerivativeJob
GenerateManifestBatchJob
```

### Single-unit job

Generates one ManifestUnit / GenerationRequest.

Simple and retryable, but too chatty at massive scale if overused.

### Batch job

Processes a batch of units or a batch of Asset IDs.

Preferred for large libraries.

Guidelines:

- keep payloads small (IDs + parameters, not image binaries);
- jobs must be retryable;
- jobs must be idempotent;
- avoid millions of tiny jobs when batching reduces overhead;
- capture failures per unit without necessarily failing an entire huge batch blindly;
- support progress reporting where practical.

---

## 6. Automatic Asset Upload / Replace Integration

Automatic queue generation is a **first-class** Phase 2 feature.

When a Craft Asset is uploaded or its file is replaced/changed, Super Images must enqueue generation from configured profiles.

```text
Asset uploaded
  or Asset file replaced
  or relevant source metadata changed (e.g. focal point, if configured)
        ↓
autoGenerate enabled for general/volume/field?
        ↓
build Generation Manifest from config
        ↓
enqueue Craft queue jobs
        ↓
Generation Service
```

Rules:

- driven by `config/super-images.php` / CP settings (`autoGenerate`);
- configurable globally and per volume/field;
- **must queue by default** — do not block the upload HTTP request with heavy AVIF/WebP work;
- must ignore saves where no Super Images profiles apply;
- must be idempotent (safe if jobs retry);
- must be safe during migrations/imports (`disableDuringImport` / bulk mute switch);
- file replacement must invalidate/create new identities as source version changes;
- progress/failures appear through normal queue + diagnostics tooling.

This is not optional polish. Sites that configure profiles for a volume should get derivatives without manually running CLI after every upload, unless auto-generate is explicitly disabled.

---

## 7. Memory and Throughput

CLI/queue must:

- page Assets;
- free resources between batches;
- not hold all manifests in memory;
- not open unlimited remote connections;
- log periodic progress;
- support failure continuation (`--continue-on-error` conceptually).

Example batch loop:

```text
page assets 100 at a time
  build manifest
  enqueue/process units
  clear caches/temp artifacts
  next page
```

---

## 8. Status Command

`super-images/status` should report high-level diagnostics, not invent a derivative DB.

Possible outputs:

```text
Drivers available: libvips, imagick, gd
Encoders: jpeg, png, webp, avif
Optimizers: jpegoptim, oxipng, cwebp
Storage default: s3
Queue jobs pending: 42
Recent generation failures: 3
```

Detailed “processed vs pending derivative counts” cannot depend on a GeneratedImage table.

If counts are shown, they must come from explicit scan modes or approximate diagnostics, clearly labeled as such.

Do not force expensive full-bucket scans on every status call.

---

## 9. Config Command

`super-images/config --asset=123` should show effective resolved configuration:

```text
General
Volume
Folder
Field
Effective profile(s)
Formats
Storage
Driver preference
```

This is invaluable for support and debugging.

It must use ConfigurationResolver.

---

## 10. Failure Handling

Failures should record:

- asset ID;
- profile/variant/format;
- identity/path;
- exception class/message (sanitized);
- job attempt;

CLI summary example:

```text
Generated: 1180
Skipped: 240
Failed: 12
```

Failed units should be re-runnable.

---

## 11. Security / Safety

CLI is trusted admin context, but still:

- do not print secrets;
- validate IDs/handles;
- refuse unsafe path overrides;
- use ProcessRunner for any external tools via Phase 1 infrastructure only.

---

## 12. Testing Requirements

- generate one asset
- generate by volume
- dry-run output
- force regenerate
- skip existing
- queue job serialization/retry
- batch paging
- asset-save enqueue path
- config dump
- failure isolation

Use fake/mocked GenerationService in unit tests and real pipeline tests sparingly in integration tests.

---

## 13. Definition of Done

- [ ] generate command works
- [ ] dry-run works
- [ ] filters work
- [ ] queue jobs work
- [ ] batching is memory-safe
- [ ] automatic enqueue on Asset upload works
- [ ] automatic enqueue on Asset file replace works
- [ ] auto-generate can be disabled globally/per volume
- [ ] upload request is not blocked by heavy encoding
- [ ] config command shows effective config
- [ ] status command reports safe diagnostics
- [ ] no duplicate processing pipeline invented
- [ ] tests cover CLI/queue orchestration and auto-generate hooks

---

## Final Rule

**CLI and queue are transporters of GenerationRequests, not alternate image engines.**

If a feature cannot be expressed as manifest expansion + GenerationService calls, it does not belong in this layer.
