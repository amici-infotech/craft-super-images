# Phase 3 — Control Panel

## Purpose

Define the Craft Control Panel experience for Super Images.

The CP must operate on the **same configuration model** used by `config/super-images.php`, CLI, Twig, queue, and runtime generation.

---

## 1. CP Information Architecture

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
├── Playground
└── Diagnostics
```

Exact nav labels may adapt to Craft UX patterns.

---

## 2. Principles

1. One configuration model.
2. CP edits resolve through the same ConfigurationResolver semantics.
3. Show inheritance clearly (General → Volume → Folder → Field).
4. Never require developers to learn a CP-only dialect.
5. PHP config remains first-class for version-controlled projects.
6. Secrets stay environment-driven where possible.
7. CP must not trigger expensive full-library scans by default.

---

## 3. Dashboard

Show actionable overview:

```text
Available drivers
Available encoders/formats
Available optimizers
Default storage adapter
Queue pending/failed counts (Craft queue)
Recent errors (sanitized)
Links to Playground / generate docs
```

Avoid fake precision.

Do not invent derivative totals from a GeneratedImage table.

If derivative estimates exist, label them as scan-based/approximate.

---

## 4. Profiles UI

Manage profiles/variants/formats/defaults:

- create/edit/delete profiles;
- define variants (width/height/mode/position/etc.);
- choose formats;
- attach reusable operation defaults (sharpen/watermark/etc.);
- validate on save;
- preview summary of expansion count (variants × formats).

Profiles created in CP must be readable by PHP runtime as the same normalized model.

---

## 5. Storage UI

Configure adapters:

- local
- S3
- Spaces
- R2
- custom registered adapters

Features:

- endpoint/region/bucket/prefix/baseUrl fields;
- env var references for secrets;
- connection test button;
- capability display;
- warning that Craft Volume storage ≠ derivative storage.

Connection tests must not expose secrets in responses/logs.

---

## 6. Encoders & Optimizers UI

Show:

- detected tools/versions;
- preferred encoder per format;
- preferred optimizer per format;
- enabled/disabled optional tools;
- fallback behavior notes.

This UI reads Capability services from Phase 1.

It should not hard-code detection logic in Twig/CP templates.

---

## 7. Volume / Folder / Field Overrides

Provide scoped override screens:

```text
General defaults
  ↓
Volume overrides
  ↓
Folder overrides
  ↓
Asset Field overrides
```

Each screen should visualize inheritance:

```text
Inherited from Volume: profile=responsive
Field override: formats=[webp,avif]
Effective: ...
```

Use ConfigurationResolver for “effective config” previews.

---

## 8. Project Config

Follow Craft 5 project config practices where settings are CP-managed.

Ensure:

- deployable config;
- no unexpected secret leakage into project YAML;
- PHP file config and project config coexistence strategy is documented.

If both exist, define clear precedence and warn on conflicts.

Recommended approach:

```text
document one primary source of truth per environment strategy
support overrides intentionally, not accidentally
```

---

## 9. Permissions

Define Craft permissions such as:

```text
viewSuperImages
manageSuperImagesSettings
useSuperImagesPlayground
runSuperImagesDiagnostics
```

Only privileged users can change storage credentials or run expensive diagnostics.

---

## 10. UX Constraints

- no Playground-like heavy generation on every settings page;
- autosave carefully with validation;
- destructive actions confirmed;
- long-running generate actions go to queue, not blocking CP request threads when possible.

---

## 11. Testing Requirements

- settings save/load
- inheritance preview correctness
- permission checks
- secret fields not re-rendered in plain text unexpectedly
- project config round-trip where applicable
- no alternate config merge logic

---

## 12. Definition of Done

- [ ] CP nav/sections exist
- [ ] Profiles manageable
- [ ] Storage configurable/testable
- [ ] Encoder/optimizer diagnostics visible
- [ ] Volume/folder/field overrides work
- [ ] Inheritance visualization works
- [ ] Permissions enforced
- [ ] Same config model as PHP/runtime

---

## Final Rule

**The Control Panel is a view over the real configuration system, not a second configuration system.**
