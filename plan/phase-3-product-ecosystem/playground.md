# Phase 3 — Playground

## Purpose

The Image Playground is a major product feature.

It lets users visually explore Super Images output before committing configuration to production templates.

Playground must call the real Generation Service.

It must not invent a preview-only processing engine.

---

## 1. User Goals

From the Playground, a user should be able to:

1. Select a Craft Asset.
2. Select a Profile.
3. Select a Variant.
4. Select an output format.
5. Override transformation settings.
6. Generate a preview.
7. Compare original vs generated dimensions.
8. Compare original vs generated file sizes.
9. See percentage size reduction.
10. See the generated URL.
11. See frontend code examples (`generateUrl` / `img` / `picture`).

---

## 2. Core UI Layout (Conceptual)

```text
┌──────────────── Playground ────────────────┐
│ Asset picker                               │
│ Profile / Variant / Format controls        │
│ Optional overrides                         │
│ [Generate Preview]                         │
├--------------------┬-----------------------┤
│ Original           │ Generated             │
│ 1920×1080 JPEG     │ 1200×675 WebP         │
│ 2.84 MB            │ 184 KB                │
│                    │ 93.5% smaller         │
├--------------------┴-----------------------┤
│ URL                                        │
│ Twig example                               │
│ PHP example                                │
└────────────────────────────────────────────┘
```

Visual design can follow Craft CP conventions; the information architecture above is required.

---

## 3. Preview Storage Policy

Playground previews must **not** pollute permanent production derivative storage by default.

Required:

```text
Playground generation
  ↓
temporary/preview storage namespace
  ↓
auto-expire / cleanup
```

Do not write playground experiments into the same immutable production derivative paths unless the user explicitly chooses a “generate for real” action.

---

## 4. Generation Path

```text
Playground request
  ↓
validate permissions
  ↓
build GenerationRequest from UI state
  ↓
GenerationService
  ↓
preview storage
  ↓
return preview URL + metrics
```

Same operations/encoders/optimizers/drivers as production.

If Playground diverges, users will tune settings that production cannot reproduce.

---

## 5. Comparison Metrics

Show at least:

```text
original width/height/format/filesize
generated width/height/format/filesize
bytes saved
percent saved
processing duration
driver/encoder/optimizer used (diagnostics)
```

Percent saved:

```text
(original - generated) / original × 100
```

Handle zero/missing sizes safely.

---

## 6. Code Generation

Provide copyable examples based on current UI state:

```twig
{{ asset|generateUrl('webp', { width: 1200, quality: 75 }) }}
```

```twig
{{ asset|generatePictureTag({ profile: 'responsive', sizes: '100vw' }) }}
```

```php
$frontend->pictureTag($asset, [...]);
```

Examples must match actual API syntax from Phase 2.

---

## 7. Safety & Limits

Even for admins:

- enforce max dimension/pixel limits;
- timeout protection;
- temp cleanup;
- no arbitrary remote URL sources;
- permission gated;
- rate-limit expensive previews if needed.

---

## 8. Multi-format Compare (Optional Enhancement)

Allow comparing JPG/WebP/AVIF side by side for the same variant.

This is highly valuable for quality/size education.

Still uses real encoders.

---

## 9. Non-Goals

- full visual node-based recipe editor in v1 (may come later);
- storing playground history permanently by default;
- replacing Twig as the primary integration path.

---

## 10. Testing Requirements

- preview uses GenerationService
- preview storage isolation
- metrics correctness
- permission checks
- code snippet accuracy
- cleanup of preview artifacts
- invalid option handling

---

## 11. Definition of Done

- [ ] Asset/profile/variant/format selection works
- [ ] Preview generation works
- [ ] Original vs generated comparison works
- [ ] URL + code examples shown
- [ ] Temporary preview storage isolation enforced
- [ ] Uses real engine path
- [ ] Tests cover preview isolation and metrics

---

## Final Rule

**Playground is a microscope on the real engine, not a toy engine of its own.**
