# Phase 2 — Generation & Delivery

Phase 2 makes the Phase 1 engine useful to developers and production websites.

It does **not** reimplement image processing, encoding, optimization, configuration resolution, or storage.

It builds generation orchestration, delivery, and frontend integration on top of Phase 1 services.

---

# Phase 2 Objective

Enable this workflow:

```text
Configure profiles/variants once
        ↓
Render lightweight Twig output
        ↓
Optionally eager-generate via CLI/queue
        ↓
Optionally lazy-generate via signed runtime URLs
        ↓
Serve from local/CDN/S3-compatible storage
```

At the end of Phase 2, a developer should be able to:

```twig
{{ asset|generatePictureTag() }}
```

and:

```bash
php craft super-images/generate
```

without manually creating every derivative.

---

# What Phase 2 Includes

```text
phase-2-generation-delivery/

├── README.md
├── manifest.md
├── cli-queue.md
├── runtime-generation.md
└── twig-frontend.md
```

## 1. Generation Manifest

Defines what derivatives should exist for an Asset/Profile.

See `manifest.md`.

## 2. CLI & Queue

Defines eager generation, dry-run, batching, and Craft queue jobs.

See `cli-queue.md`.

## 3. Runtime Generation

Defines signed lazy-generation URLs, resource limits, and concurrency locks.

See `runtime-generation.md`.

## 4. Twig & Frontend

Defines `generateUrl`, `generateImgTag`, `generatePictureTag`, srcset, and `<picture>`.

See `twig-frontend.md`.

---

# Phase 2 Non-Goals

These belong to Phase 3:

- full Control Panel settings UI
- Playground
- dashboard analytics UI
- extension marketplace polish
- cleanup command productization
- final docs/QA packaging

Phase 2 may expose services that Phase 3 will wrap in UI, but should not build a second configuration model.

---

# Core Design Principle

Phase 2 has one generation engine entry point:

```text
GenerationService (Phase 1)
```

All Phase 2 paths call it:

```text
CLI
 ↓
Generation Manifest
 ↓
Generation Service

Queue
 ↓
Generation Manifest / batched requests
 ↓
Generation Service

Runtime URL
 ↓
Signed request validation
 ↓
Generation Service

Twig
 ↓
Deterministic URL / optional runtime URL
 ↓
(HTML only; no processing on normal render)
```

There must never be a separate “Twig processor,” “CLI processor,” or “runtime processor.”

---

# Eager vs Lazy

Phase 2 supports both.

## Eager

Derivatives are generated before browser request:

```text
Asset upload/replace → automatic queue
  and/or CLI generate
      ↓
queue workers / CLI
      ↓
storage/CDN URL ready
```

Automatic queue generation on Asset upload/replace is required product behavior when configured.

## Lazy

Derivatives are generated on demand:

```text
Twig renders signed runtime URL
      ↓
browser requests URL
      ↓
runtime endpoint validates signature
      ↓
generate if missing
      ↓
serve/redirect to final storage URL
```

Both modes share:

- ConfigurationResolver
- GenerationManifest concepts
- GenerationService
- deterministic identity/paths
- storage adapters

---

# Frontend Performance Contract

Normal frontend rendering must remain:

```text
Generated-image DB queries: 0
Filesystem existence checks: 0
Remote HEAD requests: 0
Image processing: 0
Encoding: 0
Optimization: 0
```

Normal Twig path:

```text
Asset
 ↓
lightweight config resolution (cached/normalized)
 ↓
deterministic URL(s)
 ↓
HTML
```

If a derivative is missing in lazy mode, generation happens on the **runtime endpoint**, not during Twig HTML rendering.

---

# Security Contract

Runtime generation is dangerous if unrestricted.

Phase 2 must enforce:

- signed URLs
- allow-listed operations/formats
- max width/height/pixels
- max input size
- complexity limits
- local-path sources only inside configured allow-listed roots
- remote/CDN URL sources only for configured allow-listed hosts (SSRF-safe)
- no arbitrary filesystem paths from user input
- no arbitrary remote URL fetching from user input
- no unsafe shell composition
- existence markers only under private Craft `storage/`, never webroot

See `../security.md`.

---

# Concurrency Contract

If 100 requests ask for the same missing derivative:

```text
100 requests
   ↓
1 generation
   ↓
100 consumers use same result
```

Use locking/single-flight protection around lazy generation.

---

# Dependencies on Phase 1

Phase 2 may assume:

```text
ConfigurationResolver
Profiles / Variants
GenerationRequest
GenerationDefinition
GenerationIdentity
GenerationService
GenerationResult
Driver/Encoder/Optimizer/Storage managers
Domain exceptions
Capability detection
```

If a Phase 1 service is missing, stop and complete Phase 1 rather than inventing a parallel system.

---

# Phase 2 Milestones

## Milestone 1 — Manifest

- expand profile/variant/formats into generation units
- dry-run listing
- deterministic identity per unit

## Milestone 2 — CLI/Queue

- generate command
- asset/volume/profile filters
- queue jobs
- batching
- automatic enqueue on Asset upload/replace
- status basics needed for generation

## Milestone 3 — Runtime

- signed URLs
- runtime controller/action
- resource limits
- locks
- redirect/serve final URL

## Milestone 4 — Twig/Frontend

- generateUrl
- generateImgTag
- generatePictureTag
- srcset/sizes
- format ordering

## Milestone 5 — Integration hardening

- eager + lazy coexistence
- CDN URL correctness
- no frontend existence checks
- tests for all delivery paths

---

# Definition of Done

Phase 2 is complete when:

- [ ] Manifest can expand a profile into derivative units
- [ ] CLI can generate and dry-run
- [ ] Queue can generate in batches
- [ ] Automatic queue generation runs on Asset upload/replace
- [ ] Runtime signed generation works safely
- [ ] Local-path and allow-listed remote URL sources work end-to-end
- [ ] Concurrency lock prevents duplicate generation
- [ ] Twig URL/img/picture APIs work for Assets and non-Asset sources
- [ ] Responsive srcset/picture output works
- [ ] Normal Twig render does no processing/existence/marker I/O
- [ ] Eager and lazy modes share GenerationService
- [ ] Tests cover manifest, CLI/queue, runtime security, Twig output

---

# Final Phase 2 Principle

**Delivery should be cheap. Generation should be shared. Security should be strict.**

Phase 2 succeeds when websites can render modern responsive images with almost no runtime cost, while still having robust eager and lazy generation paths into the same engine.
