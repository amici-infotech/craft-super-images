# Phase 3 — Final QA

## Purpose

Define release-readiness quality assurance for Super Images as a production Craft CMS 5 plugin.

This is not a substitute for phase-level tests.

It is the final integration gate across engine, delivery, product UX, docs, and operational safety.

---

## 1. Release Goals

Before v1 release, Super Images must be:

- architecturally consistent with `plan/README.md` invariants;
- functionally complete across Phases 1–3 definition-of-done checklists;
- secure for runtime generation;
- performant on normal frontend renders;
- operable via CLI/queue/CP;
- documented for developers and admins;
- tested on realistic Craft 5 environments.

---

## 2. Architectural Regression Checklist

Verify all remain true:

1. One canonical generation pipeline.
2. One configuration resolver and precedence model.
3. No GeneratedImage database table.
4. No permanent local mirror requirement for remote storage.
5. Deterministic derivative identity/paths.
6. Normal Twig render performs no processing/exists/HEAD I/O.
7. Runtime generation is signed and limited.
8. CLI/queue/Twig/Playground all call the same engine.
9. Operations/encoders/optimizers/storage remain separated.
10. Secrets never appear in logs, identity, or Twig output.

If any fail, release is blocked.

---

## 3. Functional QA Matrix

### Engine

- [ ] Libvips path (where available)
- [ ] Imagick path
- [ ] GD fallback path
- [ ] JPEG/PNG/WebP/AVIF encode
- [ ] optional optimizers detect/run/fallback
- [ ] geometry/color/effect/watermark ops
- [ ] focal-point crop

### Storage

- [ ] local adapter
- [ ] S3
- [ ] DigitalOcean Spaces
- [ ] Cloudflare R2
- [ ] CDN/baseUrl correctness
- [ ] temp cleanup

### Delivery

- [ ] manifest expansion
- [ ] CLI generate + dry-run
- [ ] queue batching
- [ ] runtime signed URL success/fail cases
- [ ] concurrency single-flight
- [ ] Twig url/img/picture
- [ ] responsive srcset + format order

### Product

- [ ] CP settings save/load
- [ ] inheritance visualization
- [ ] Playground preview isolation
- [ ] diagnostics/doctor
- [ ] cleanup dry-run
- [ ] extension registration smoke test

---

## 4. Performance QA

Measure and record:

### Frontend render budget

For a page with multiple picture tags:

```text
expected:
0 derivative DB queries
0 derivative exists checks
0 remote HEAD calls
0 image encodes
```

### Generation benchmarks

Sample timings for representative images:

```text
small / medium / large source
JPEG / WebP / AVIF outputs
with and without optimizers
local vs S3 write
```

### Memory

Ensure large sources do not exhaust PHP memory under preferred driver settings.

### CLI throughput

Batch generate a realistic volume subset without unbounded memory growth.

---

## 5. Security QA

- [ ] unsigned runtime requests rejected
- [ ] tampered signatures rejected
- [ ] expired signatures rejected
- [ ] over-limit transforms rejected
- [ ] path traversal rejected
- [ ] arbitrary remote source rejected
- [ ] secrets not logged
- [ ] CP permission boundaries enforced
- [ ] ProcessRunner used for external binaries
- [ ] Playground cannot write unmanaged paths

Consider threat-model review focused on runtime endpoint and storage credentials.

---

## 6. Compatibility QA

Test on:

```text
Craft CMS 5.x current minor
PHP 8.2 / 8.3 (+ 8.4 if supported target)
MySQL/PostgreSQL as relevant to Craft install
local FS and S3-compatible storage
with/without Imagick
with/without Libvips
with/without optional optimizer binaries
```

Document supported/unsupported environment combinations honestly.

---

## 7. Documentation QA

Required docs:

- installation
- configuration reference (`config/super-images.php`)
- profiles/variants
- Twig API
- CLI reference
- storage setup (S3/Spaces/R2)
- runtime/lazy mode security notes
- queue/eager generation
- Playground usage
- extension API
- troubleshooting/doctor
- upgrade/identity invalidation notes

Docs must match implemented syntax, not outdated ChatGPT draft names.

---

## 8. Migration / Upgrade QA

Verify:

- config schema versioning behavior;
- identity changes invalidate old paths as designed;
- cleanup can target obsolete artifacts safely;
- upgrading does not require GeneratedImage migrations (because none exist).

---

## 9. Acceptance Scenarios

### Scenario A — Agency brochure site

Configure responsive profile, Twig picture tags, local or CDN storage, CLI generate on deploy.

Expect fast frontend and correct `<picture>` output.

### Scenario B — Large asset library

Tens/hundreds of thousands of Assets, queue generation, S3/R2 storage.

Expect batching stability and idempotent reruns.

### Scenario C — Lazy first / eager later

Lazy mode for launch, then CLI backfill, then optional switch to eager URLs.

Expect identity parity and no duplicate divergent files for same inputs.

### Scenario D — Playground-driven tuning

Editor compares AVIF/WebP sizes, copies Twig snippet into template, deploy works the same.

---

## 10. Release Blockers

Block release for:

- unsigned runtime transforms;
- frontend render-time generation/exists I/O as default behavior;
- introduction of per-derivative DB as required source of truth;
- secret leakage;
- data-loss bugs in cleanup defaults;
- major identity non-determinism;
- Twig API unable to render picture/srcset for configured profiles.

---

## 11. Soft Issues (Can Ship with Tracking)

- missing optional optimizer on some OS packages;
- GD lacking advanced ops (with clear capability warnings);
- CP polish nits;
- docs examples still expanding;
- non-critical Playground UX refinements.

---

## 12. Test Automation Gate

Final CI should run at least:

- unit tests for config/identity/operations;
- integration tests for pipeline where environment allows;
- Twig HTML tests;
- runtime signature tests;
- storage adapter tests with mocks/fixtures;
- permission/CP smoke tests where practical.

Flaky external-binary tests should be environment-gated, not silently skipped without markers.

---

## 13. Definition of Done (Release)

- [ ] Phases 1–3 DoD checklists completed
- [ ] Architectural regression checklist passed
- [ ] Security QA passed
- [ ] Performance budgets verified
- [ ] Compatibility matrix documented
- [ ] Docs accurate
- [ ] Acceptance scenarios verified
- [ ] No release blockers open

---

## Final Rule

**Ship only when the architecture still holds under real Craft workloads.**

A feature-rich plugin that breaks the no-DB, no-render-I/O, one-pipeline contracts is not ready — even if demos look good.
