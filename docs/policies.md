# Policies

Super Images behaviour that applies across generation, cleanup, and Twig delivery is grouped under the `policies` key in `config/super-images.php`.

```php
'policies' => [
    'encode' => [ /* … */ ],
    'geometry' => [ /* … */ ],
    'safety' => [ /* … */ ],
    'cleanup' => [ /* … */ ],
    'fallback' => [ /* … */ ],
],
```

---

## Encode

Controls how encoded derivatives are written (metadata stripping, progressive JPEG, PNG compression level).

| Key | Default | Effect |
|---|---|---|
| `stripMetadata` | `true` | Remove EXIF/IPTC from output when the driver supports it |
| `progressive` | `false` | Progressive/interlaced output where supported |
| `pngCompression` | `6` | PNG compression level (0–9) |

---

## Geometry

| Key | Default | Effect |
|---|---|---|
| `allowUpscale` | `false` | When false, resize/fit operations will not enlarge beyond the source dimensions |

---

## Safety

| Key | Default | Effect |
|---|---|---|
| `maxSourcePixels` | `40_000_000` | Reject sources whose width × height exceeds this limit after load |

---

## Cleanup

Automatic derivative removal tied to Craft asset lifecycle events. Uses a lightweight per-asset index stored under `@storage/super-images/asset-index/{assetId}.json` — not a database table and not a full storage scan.

| Key | Default | Effect |
|---|---|---|
| `onAssetDelete` | `true` | Purge indexed derivatives before the Craft asset is deleted |
| `onAssetReplace` | `true` | Purge indexed derivatives when an asset file is replaced, before new generation is enqueued |

Each indexed entry records the derivative identity, storage path, and adapter handle. Purge deletes the storage object, removes the existence marker (for remote adapters), and clears the index file.

Preview artifacts under the `preview/` namespace are handled separately — see [CLI cleanup](./cli.md) and `cleanup.previewRetentionDays`.

---

## Fallback

Optional Twig delivery fallback when planning fails (missing asset, invalid source, etc.). Applies to `url()`, `img()`, `picture()`, and `srcset()` — not to strict `generate()`.

| Key | Default | Effect |
|---|---|---|
| `enabled` | `false` | When true, retry planning once with the fallback asset |
| `assetId` | `null` | Craft asset ID to use as fallback (must differ from the requested asset) |

Example — show a placeholder image when a hero asset is missing:

```php
'policies' => [
    'fallback' => [
        'enabled' => true,
        'assetId' => 42, // placeholder Asset in Craft
    ],
],
```

```twig
{{ craft.superImages.img(entry.heroImage.one(), { profile: 'responsive', variant: 'md' }) }}
```

If the hero asset cannot be resolved, Super Images plans delivery using asset `42` with the same profile/variant/format options. Fallback is attempted at most once per call.

---

## Related

- [Configuration](./configuration.md)
- [Storage & markers](./storage.md)
- [Twig delivery](./twig.md)
