# Super Images — frontend demo

Interactive Twig demo shipped with the plugin. Copy it into your Craft project to explore helpers, operations, and config live.

## Install into a project

```bash
# From a Craft project root
cp -R vendor/amici/craft-super-images/demo templates/super-images
```

Or from a local plugin checkout:

```bash
cp -R plugins/craft-super-images/demo templates/super-images
```

Open `/super-images` in the browser.

## Requirements

- Craft CMS 5 with Super Images installed
- **Portable by default:** demos use a Picsum CDN URL — enable remote sources:

```php
'sources' => [
    'remote' => [
        'enabled' => true,
        'allowedHosts' => ['picsum.photos', '*.picsum.photos'],
    ],
],
```

- Optional: `?asset=ID` in the sidebar to test Craft Assets
- Optional: Imagick for watermark / text / overlay / sepia / saturation
- Optional: a local logo under an allowed root for image watermark & overlay cards

## Pages

| Path | What you’ll learn |
|------|-------------------|
| `/super-images` | Overview + quick start |
| `/super-images/delivery` | `url`, `img`, `picture`, `srcset`, HTML attrs, operations override |
| `/super-images/formats` | WebP / JPEG / PNG / AVIF + `supportsFormat()` |
| `/super-images/geometry` | fit, crop, fill, resize, scale, rotate, flip |
| `/super-images/color` | grayscale, sepia, invert, brightness, contrast, saturation |
| `/super-images/effects` | blur, sharpen, stacked combos |
| `/super-images/composition` | border, padding, background, text watermark, text |
| `/super-images/sources` | CDN, Asset, ID, local path, allow-lists |
| `/super-images/generate` | `tryGenerate` / metadata / `isEnabled` |
| `/super-images/config` | Full config schema walkthrough |
| `/super-images/reference` | CP & CLI cheat sheet |

Sidebar search filters by label/keywords; **Enter** opens the first match. Theme toggle lives at the **top** of the sidebar (and in the mobile header).

## Stack

- Light / dark theme (Tailwind CDN)
- Alpine.js (nav search, preview/code tabs, copy, theme)
- Prism (Twig / PHP / bash highlighting)
- No build step

## Files

```text
demo/
  _layout.twig     Shell + CDN-first demoSource + nav search
  _macros.twig     Pills / code blocks / result meta
  _card.twig       Preview + code card
  index.twig
  delivery.twig
  formats.twig
  geometry.twig
  color.twig
  effects.twig
  composition.twig
  sources.twig
  generate.twig
  config.twig
  reference.twig
  README.md
```

## Tips

- Passing `operations` **replaces** the profile variant pipeline — always start with geometry (`fit` / `crop` / `fill`).
- Code samples use a portable CDN string by default (`demoSourceCode`).
- `tryGenerate` soft-fails unsupported ops so the page keeps loading.
- Pages are `noindex` via `X-Robots-Tag`.

See the full docs: [Interactive demo](../docs/demo.md) · [Twig API](../docs/twig.md) · [Configuration](../docs/configuration.md)
