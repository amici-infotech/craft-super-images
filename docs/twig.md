# Twig & frontend

Normal template render **plans URLs only**. It must not process images, check derivative existence, or touch existence markers.

Use the **variable API** only (`craft.superImages`). Twig filters are not provided.

---

## Variable API (`craft.superImages`)

```twig
{# Delivery URL (lazy signed or eager storage, per delivery.mode) #}
{{ craft.superImages.url(asset, { profile: 'responsive', variant: 'md', format: 'webp' }) }}

{# <img> #}
{{ craft.superImages.img(asset, {
    profile: 'responsive',
    variant: 'md',
    format: 'webp',
    alt: entry.title,
    class: 'thumb',
    sizes: '100vw'
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
        width: 1200,
        decoding: 'sync'
    }
}) }}

{# <picture> with multi-width srcsets (all profile variants × formats) #}
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

{# Explicit eager generate (processes now) #}
{% set result = craft.superImages.generate(asset, { variant: 'md', format: 'webp' }) %}
{{ result.url }}

{# Soft eager generate for demos #}
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

## Delivery modes

| `delivery.mode` | What Twig emits |
|---|---|
| `lazy` | Signed runtime action URL |
| `eager` | Final storage/CDN URL |
| `hybrid` | Storage URL (currently same as eager) |

Use `generate()` / CLI / auto-generate when you need files to exist before first page view.

---

## Performance rules

Do **not** call `generate()` inside list/gallery templates unless you intentionally want blocking eager generation.

Prefer:

```twig
{{ craft.superImages.img(asset, { variant: 'md', format: 'webp' }) }}
```

and either:

- lazy runtime generation, or
- pre-warm via `php craft super-images/generate` / queue / autoGenerate.
