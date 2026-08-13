# Phase 2 — Twig & Frontend

## Purpose

This document defines the frontend developer API for Super Images.

The Twig layer must make modern responsive images easy while preserving the Phase 1/2 performance contract:

```text
Twig render = lightweight URL/HTML composition
Runtime endpoint = optional generation
CLI/queue = eager generation
```

Twig must never become an image processor.

---

## 1. First-Class Twig API

Primary filters/functions must support Craft Assets **and** non-Asset sources:

```twig
{{ asset|generateUrl() }}
{{ asset|generateUrl('webp') }}
{{ asset|generateUrl('webp', { width: 1200, quality: 75 }) }}

{{ asset|generateImgTag() }}
{{ asset|generateImgTag('webp') }}
{{ asset|generateImgTag('webp', { width: 1200, alt: entry.title }) }}

{{ asset|generatePictureTag() }}
{{ asset|generatePictureTag(['avif', 'webp', 'jpg']) }}
{{ asset|generatePictureTag({ profile: 'responsive', sizes: '100vw' }) }}

{# Local folder / public path images #}
{{ '/images/abc.png'|generateUrl('webp') }}
{{ '/images/abc.png'|generatePictureTag({ profile: 'responsive' }) }}

{# Allow-listed CDN / remote originals #}
{{ 'https://cdn.example.com/media/hero.jpg'|generateUrl('avif') }}
{{ 'https://cdn.example.com/media/hero.jpg'|generatePictureTag() }}
```

Exact syntax may be refined, but these capabilities are mandatory:

1. URL
2. `<img>`
3. `<picture>`
4. Craft Asset sources
5. Local-path sources (allow-listed roots)
6. Remote/CDN URL sources (allow-listed hosts)

Local/remote sources use the same Generation Service / URL services as Assets. They are not a second mini-plugin.

---

## 2. Design Goals

- simple defaults from configuration;
- explicit overrides when needed;
- excellent responsive output;
- deterministic URLs;
- zero derivative existence I/O during render;
- accessible HTML defaults where practical;
- no heavy logic in Twig templates.

---

## 3. URL Generation

`generateUrl` returns a string URL for one derivative unit.

Resolution order conceptually:

```text
explicit args
  ↓
field/volume/folder/general config
  ↓
profile/variant/format defaults
  ↓
deterministic public URL or signed runtime URL
```

Rules:

- if format omitted, use configured default format;
- if profile omitted, use effective default profile when configured;
- custom options are validated/normalized;
- result is a URL string only.

Pseudo:

```twig
{{ asset|generateUrl('webp', { variant: 'md' }) }}
```

---

## 4. Img Tag Generation

`generateImgTag` returns an `<img>` element.

Minimum responsibilities:

- `src`
- optional `srcset`
- optional `sizes`
- `width` / `height` when known from variant intent or metadata policy
- `alt` handling
- class/loading/decoding attributes passthrough

Example:

```twig
{{ asset|generateImgTag('webp', {
    profile: 'responsive',
    sizes: '(min-width: 992px) 992px, 100vw',
    class: 'hero__image',
    loading: 'lazy',
    alt: entry.title,
}) }}
```

Possible output:

```html
<img
  src="https://cdn.example.com/...-md.webp"
  srcset="...-sm.webp 576w, ...-md.webp 768w, ...-lg.webp 992w"
  sizes="(min-width: 992px) 992px, 100vw"
  width="768"
  height="432"
  alt="..."
  class="hero__image"
  loading="lazy"
  decoding="async"
>
```

---

## 5. Picture Tag Generation

`generatePictureTag` is a major product feature.

It should generate:

```html
<picture>
  <source type="image/avif" srcset="..." sizes="...">
  <source type="image/webp" srcset="..." sizes="...">
  <img src="..." srcset="..." sizes="..." alt="...">
</picture>
```

Default format preference:

```text
AVIF → WebP → JPEG/PNG fallback
```

unless configured otherwise.

Only include formats that are configured/allowed.

---

## 6. Responsive Srcset Model

A responsive profile with width variants maps to `w` descriptors:

```text
576w
768w
992w
1280w
1600w
```

Rules:

- variant width intent becomes descriptor;
- do not invent fake densites unless explicitly supporting `x` descriptors;
- `sizes` must be author-provided or defaulted carefully;
- missing sizes should use a safe default such as `100vw` when srcset is present, or omit according to documented policy.

---

## 7. Alt Text and Accessibility

API should encourage alt text.

Possible sources:

- explicit Twig arg;
- Asset title/alt field conventions if configured;
- empty alt only when decorative and explicitly intended.

Do not silently invent marketing copy.

Preserve ability to pass ARIA/role attributes where needed.

---

## 8. Dimension Attributes

Where variant target dimensions are known, include `width` and `height` to reduce CLS.

If only width is known, height may be inferred from source aspect ratio when safe.

Do not perform expensive I/O just to populate dimensions during render.

Prefer:

```text
configured variant dimensions
or already available Asset width/height
```

---

## 9. Delivery Mode Awareness

Twig helpers ask a URL service:

```text
UrlGenerator / DeliveryUrlService
```

That service decides:

```text
final storage/CDN URL
  or
signed runtime URL
```

based on config mode and unit identity.

Twig helpers do not branch on filesystem/S3 existence.

---

## 10. Configuration-Driven Defaults

Ideal developer experience:

```php
'fields' => [
    'heroImage' => [
        'profiles' => ['responsive'],
    ],
],
```

Then:

```twig
{{ asset|generatePictureTag() }}
```

just works.

Overrides remain available for one-off cases.

---

## 11. Runtime Custom Transforms

Allowed:

```twig
{{ asset|generateUrl('webp', { width: 1200, sharpen: 10 }) }}
```

Requirements:

- options validated against allow-lists/limits;
- contribute to identity;
- in lazy mode, included in signature payload;
- never accept unsafe keys.

---

## 12. What Twig Must Not Do

Twig/extensions must not:

- decode/encode images;
- call optimizers;
- write storage;
- query GeneratedImage tables;
- stat/HEAD derivatives on render;
- read existence markers on render;
- shell out;
- download remote originals during HTML render.

Source allow-list validation for local/remote inputs may occur while building deterministic/signed URLs, but must remain cheap and must not fetch or process image bytes.

If a helper needs processing I/O on render, the architecture is wrong.

---

## 13. PHP API Parity

Provide PHP equivalents for non-Twig contexts:

```php
SuperImages::getInstance()->frontend->url($asset, 'webp', [...]);
SuperImages::getInstance()->frontend->imgTag($asset, ...);
SuperImages::getInstance()->frontend->pictureTag($asset, ...);
```

Exact service naming can follow Craft patterns.

Twig should be a thin wrapper over the same service.

---

## 14. HTML Safety

- escape attributes correctly;
- allow intentional raw HTML only through controlled builders;
- sanitize/validate class/sizes values appropriately;
- never print secrets or signatures in visible text content;
- signed URLs may appear in `src`/`srcset` attributes when lazy mode requires them.

---

## 15. Caching of Render Helpers

It is acceptable to cache:

- normalized config slices;
- format preference lists;
- computed srcset structures for identical inputs within a request.

Do not cache across config changes incorrectly.

Do not build a derivative DB under the guise of Twig caching.

---

## 16. Error Behavior

If configuration is invalid:

- fail clearly in dev/staging;
- optionally degrade safely in production according to config;

Never output broken half-picture markup silently if formats/profiles are misconfigured.

A controlled fallback to original Asset URL may be offered, but must be explicit.

---

## 17. Testing Requirements

- URL output for profile/variant/format
- img tag attributes
- picture tag order AVIF/WebP/fallback
- srcset descriptors
- sizes passthrough
- alt handling
- lazy mode emits signed URLs
- eager mode emits storage/CDN URLs
- no storage exists() calls during render
- XSS-safe attribute escaping
- PHP/Twig parity

---

## 18. Definition of Done

- [ ] generateUrl works
- [ ] generateImgTag works
- [ ] generatePictureTag works
- [ ] responsive srcset works
- [ ] format ordering works
- [ ] config defaults work without verbose Twig
- [ ] runtime/eager URL modes work
- [ ] no render-time processing/existence I/O
- [ ] tests cover HTML and URL services

---

## Final Rule

**Frontend helpers compose URLs and HTML. They do not create images.**

The best Twig API is the one that makes the right thing easy and the expensive thing impossible on the render path.
