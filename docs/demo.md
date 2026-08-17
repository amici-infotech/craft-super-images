# Interactive demo

Super Images ships a ready-made Twig demo so you can click through every helper and operation with live previews and copy-paste code.

---

## What you get

- **CDN-first originals** — no site-specific Asset ID required
- **Sidebar search** — jump to Geometry, Watermark, Config, …
- **Light / dark theme** — toggle at the top of the sidebar
- **Preview + code cards** — see the result and the exact Twig
- **Config walkthrough** — full `config/super-images.php` schema on `/super-images/config`

---

## Install

From your Craft project root:

```bash
cp -R vendor/amici/craft-super-images/demo templates/super-images
cp vendor/amici/craft-super-images/demo/assets/super-images-demo.jpg web/images/
```

Then open:

```text
https://yoursite.test/super-images
```

Demo pages call `tryGenerate` many times (e.g. Geometry). The bundled **`web/images/super-images-demo.jpg`** avoids re-fetching a remote CDN on every card. Without it, demos fall back to Picsum (requires remote allow-list below).

### Remote sample (optional fallback)

If the local demo file is missing, demos use Picsum. Allow it once:

```php
// config/super-images.php
'sources' => [
    'remote' => [
        'enabled' => true,
        'allowedHosts' => [
            'picsum.photos',
            '*.picsum.photos', // redirects (e.g. Fastly)
        ],
        'timeout' => 10,
    ],
],
```

### Optional Asset override

Use the sidebar “Optional: override with Asset ID” form. The `?asset=` query string is preserved across demo pages.

---

## Page map

| URL | Focus |
|---|---|
| `/super-images` | Overview, quick start, sample output |
| `/super-images/delivery` | `url`, `img`, `picture`, `srcset`, HTML attributes |
| `/super-images/formats` | Format support + side-by-side output |
| `/super-images/geometry` | fit, crop, fill, resize, scale, rotate, flip |
| `/super-images/color` | grayscale, sepia, invert, brightness, contrast, saturation |
| `/super-images/effects` | blur, sharpen, stacked pipelines |
| `/super-images/composition` | border, padding, background, text watermark, text |
| `/super-images/sources` | CDN, Asset, ID, local path, allow-lists |
| `/super-images/generate` | `tryGenerate` result metadata |
| `/super-images/config` | Configuration reference (friendly) |
| `/super-images/reference` | CP + CLI cheat sheet |

---

## How demos stay portable

Layout bootstrap (`_layout.twig`):

1. Default source = local `/images/super-images-demo.jpg` when present  
2. Else Picsum CDN URL  
3. Optional `?asset=` → Craft Asset  
4. Twig code samples use `demoSourceCode` so copied snippets work on any site  

Image watermark / overlay examples that need a **local file path** are shown as code-only (Imagick cannot read a remote watermark URL the same way).

---

## Driver notes

| Feature | Typical driver |
|---|---|
| fit / crop / fill / resize / scale / flip | All |
| rotate with background | Imagick preferred |
| sepia / saturation | Imagick |
| watermark (text or image), overlay, text | Imagick |
| blur / sharpen / brightness / contrast | All (options differ) |

Sepia `threshold` is **0–100** with ~**80** as the classic look. Lower values are harsher, not “softer”.

---

## Operations reminder

When you pass `operations`, they **replace** the profile variant pipeline. Always start with geometry:

```twig
{{ craft.superImages.img(asset, {
  format: 'jpg',
  operations: [
    { type: 'fit', width: 640 },
    { type: 'grayscale' },
  ],
}) }}
```

---

## Related docs

- [Twig & frontend](./twig.md)
- [Configuration](./configuration.md)
- [Storage & naming](./storage.md)
- Plugin folder: `demo/`
