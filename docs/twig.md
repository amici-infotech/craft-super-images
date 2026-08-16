# Twig & frontend

Use the **variable API** only (`craft.superImages`). Twig filters are not provided.

When `enabled => false` in `config/super-images.php`, Twig helpers still run but emit the **original** Asset/local/remote URL (no transforms, no errors). Use `craft.superImages.isEnabled()` if a template needs to branch.

With `generateBeforePageLoad = true`, missing derivatives are created during the page request (same idea as Craft transforms). With `false`, Twig emits signed action URLs and generation happens on the first browser hit.

---

## Variable API (`craft.superImages`)

```twig
{# Delivery URL (storage URL, or signed action URL when generateBeforePageLoad is false) #}
{{ craft.superImages.url(asset, { profile: 'responsive', variant: 'md', format: 'webp' }) }}

{# <img> — one derivative, single src (no srcset). Default variant is the first/profile default. #}
{{ craft.superImages.img(asset, {
    profile: 'responsive',
    variant: 'md',
    format: 'webp',
    alt: entry.title,
    class: 'thumb'
}) }}

{# Any extra HTML attributes (top-level or attrs bag) #}
{{ craft.superImages.img(asset, {
    variant: 'md',
    format: 'webp',
    id: 'hero-image',
    class: 'hero__img',
    fetchpriority: 'high',
    'data-reveal': 'true',
    attrs: {
        width: 1200
    }
}) }}

{# <picture> — multi-width srcsets (profile variants × formats) #}
{{ craft.superImages.picture(asset, {
    profile: 'responsive',
    formats: ['webp', 'jpg'],
    sizes: '(min-width: 992px) 992px, 100vw'
}) }}

{# pictureAttrs on <picture>, attrs on inner <img>, sourceAttrs on each <source> #}
{{ craft.superImages.picture(asset, {
    profile: 'responsive',
    sizes: '100vw',
    pictureAttrs: {
        class: 'hero__picture',
        'data-component': 'responsive-image'
    },
    class: 'hero__img',
    alt: entry.title,
    fetchpriority: 'high',
    sourceAttrs: {
        media: '(min-width: 0px)'
    }
}) }}

{# Optional: limit variants, or set fallback <img src> variant #}
{{ craft.superImages.picture(asset, {
    profile: 'responsive',
    variants: ['sm', 'md', 'lg', 'xl'],
    variant: 'lg',
    sizes: '100vw'
}) }}

{# srcset string for multiple variants #}
{% set srcset = craft.superImages.srcset(asset, {
    profile: 'responsive',
    format: 'webp',
    variants: ['sm', 'md', 'lg', 'xl']
}) %}

{# Explicit generate now (bypasses delivery setting) #}
{% set result = craft.superImages.generate(asset, { variant: 'md', format: 'webp' }) %}
{{ result.url }}

{# Soft generate for demos (swallows failures) #}
{% set result = craft.superImages.tryGenerate(asset, { variant: 'md', format: 'webp' }) %}
```

### Sources

| Input | Example |
|---|---|
| Craft Asset | `asset` |
| Asset ID | `123` |
| Local path | `'/images/hero.png'` |
| Remote URL | `'https://cdn.example.com/hero.jpg'` |

Local/remote sources use the same pipeline as Assets and must pass allow-lists in config.

---

## Delivery

| Setting | Effect |
|---|---|
| `generateBeforePageLoad` | `true` = generate during Twig + storage URL; `false` = action URL when missing; omit = mirror Craft `generateTransformsBeforePageLoad` |
| `thumbnail` | Tiny server-generated `src` for `picture()` — see [thumbnail placeholder](#thumbnail-placeholder-src) |

```php
'delivery' => [
    'generateBeforePageLoad' => true,
],
'runtime' => [
    'enabled' => true, // needed when generateBeforePageLoad is false
],
```

---

## Thumbnail placeholder (`src`)

When full candidates use signed action URLs (`generateBeforePageLoad = false`), Super Images can **generate a tiny derivative on the server** and put that **storage URL** in `src` so the `<img>` is not blank while larger files generate. Full candidates stay in `srcset` / `<source>`:

```html
<picture>
  <source type="image/webp" srcset="…sm.webp 576w, …md.webp 768w, …">
  <img
    src="/transforms/super-images/…/photo-thumb.jpg"
    srcset="…sm.jpg 576w, …md.jpg 768w, …"
    sizes="100vw"
    width="768"
    height="…"
  >
</picture>
```

`src` is the server-generated thumbnail storage URL when `delivery.thumbnail` is enabled (otherwise a transparent SVG data URI). Layout space is reserved with **both** `width` and `height` (height is derived from the asset aspect ratio when the variant only sets width). Full candidates stay in `srcset` / `<source>`.

Config (`delivery.thumbnail`):

```php
'delivery' => [
    'generateBeforePageLoad' => false,
    'thumbnail' => [
        'enabled' => true,
        'width' => 32,
        'format' => 'jpg',
        'quality' => 50,
        'variant' => 'thumb',
    ],
],
```

Per-call overrides:

```twig
{# Disable thumbnail for this picture only #}
{{ craft.superImages.picture(asset, { thumbnail: false }) }}

{# Or via enabled flag / size override #}
{{ craft.superImages.picture(asset, { thumbnail: { enabled: false } }) }}
{{ craft.superImages.picture(asset, { thumbnail: { width: 48 } }) }}
```

First request for an asset may spend a few ms generating the thumb; later requests skip generation when the file already exists.

`img()` does not use thumbnails or srcset — it emits one `src` for one variant. Use `picture()` for responsive delivery.

---

## Operations (custom pipelines)

When you pass `operations`, they **replace** the profile variant pipeline for that call. Always start with geometry.

```twig
{{ craft.superImages.img(asset, {
  format: 'jpg',
  variant: 'hero-ops',
  operations: [
    { type: 'fill', width: 1200, height: 630, position: 'center-center' },
    { type: 'brightness', level: 5 },
    {
      type: 'watermark',
      text: '© Acme',
      color: '#ffffff',
      opacity: 0.7,
      position: 'bottom-right',
      size: 28,
      padding: 24,
    },
  ],
  alt: entry.title,
}) }}
```

### Built-in operation types

| Group | Types | Notes |
|---|---|---|
| Geometry | `fit`, `crop`, `fill`, `resize`, `scale`, `rotate`, `flip` | Start here |
| Color | `grayscale`, `sepia`, `invert`, `brightness`, `contrast`, `saturation` | Sepia / saturation need Imagick |
| Effects | `blur`, `sharpen` | Option names vary by driver |
| Composition | `border`, `padding`, `background`, `watermark`, `overlay`, `text` | Watermark/overlay/text need Imagick |

**Sepia:** `threshold` / `amount` is `0–100`. Default / sweet spot is **80**. Lower values look harsher, not softer.

**Text watermark (no local file):**

```twig
{ type: 'watermark', text: 'PROOF', angle: 'diagonal', cover: true, opacity: 0.55, color: '#ffffff' }
```

**Image watermark** needs a readable local path under an allowed root (not a CDN URL).

Try these live in the [interactive demo](./demo.md).

---

## Helpers cheat sheet

| Helper | Returns | Typical use |
|---|---|---|
| `url(source, options)` | string URL | Background images, custom markup |
| `img(source, options)` | `<img>` HTML | Single derivative |
| `picture(source, options)` | `<picture>` HTML | Responsive multi-format |
| `srcset(source, options)` | `url Nw, …` string | Roll-your-own `<img>` |
| `generate(source, options)` | result object (throws on failure) | Explicit generate-now |
| `tryGenerate(source, options)` | result or `null` | Demos / soft failure |
| `supportsFormat(format)` | bool | Capability checks |
| `isEnabled()` | bool | Branch when plugin disabled |

Reserved option keys (never become HTML attributes):  
`profile`, `variant`, `variants`, `format`, `formats`, `storage`, `operations`, `preview`, `thumbnail`, `alt`, `loading`, `sizes`, `attrs`, `attributes`, `imgAttrs`, `pictureAttrs`, `pictureAttributes`, `sourceAttrs`, `sourceAttributes`.

---

## Performance rules

Do **not** call `generate()` inside list/gallery templates unless you intentionally want extra blocking generation beyond `generateBeforePageLoad`.

Prefer:

```twig
{{ craft.superImages.img(asset, { variant: 'md', format: 'webp' }) }}
```

and either:

- `generateBeforePageLoad = true` (Craft-style, generate during Twig),
- `generateBeforePageLoad = false` with runtime action URLs, and/or
- pre-warm via `php craft super-images/generate` / queue / autoGenerate.

---

## Related

- [Interactive demo](./demo.md)
- [Delivery / runtime](./delivery.md)
- [Configuration](./configuration.md)
