# Release Notes for Super Images

## Unreleased
- Interactive frontend demo package shipped as `demo/` (copy to `templates/super-images/`).
- Storage path naming is configurable (`storage.naming`) with token templates; defaults include `{transformHash}` so changing operations/settings no longer reuses stale asset cache paths.
- CP Settings: Derivative naming editor + token glossary + live path examples.
- Imagick sepia: pass threshold 0–100 directly (no QuantumRange multiply) — fixes solid-color output on ImageMagick 7 HDRI.
- Extensibility: optimizer `select()` honors registered tools; storage adapters support config `type` factories; operations duck-type driver methods for third-party drivers; custom drivers join auto-fallback.
- Cleanup: removed leftover planning artifact, dead driver helpers, duplicate GD geometry branch; scrubbed competitor product names from comments/docs.
- Docs refreshed: demo guide, storage naming, Twig operations, configuration, CP settings, extension API examples.

## 5.0.2
- `enabled => false` is a real kill switch for generation: CLI generate, queue jobs, runtime endpoint, auto-generate, and Playground generate all refuse to process.
- Twig `url()` / `img()` / `picture()` / `srcset()` keep working while disabled by falling back to the **original** Asset/local/remote URL (no empty broken images).
- Added `craft.superImages.isEnabled()` and `Plugin::isEnabled()`.

## 5.0.1
- CLI `generate` starts instantly: totals are estimated from profile × variant × format counts instead of running `plan()` for every derivative up front.
- Storage paths use a folder-grouped layout: `{folderHash}/{assetId}/{basename}-{variant}.{ext}`.
- Bulk generation reuses one resolved source per asset (local volumes no longer `getCopyOfFile()` per variant), batches derivative-index writes, and defers temp cleanup until the asset is done.
- CLI `cleanup` default deletes **aged** transforms (by `generatedRetentionDays`); `--all=1` wipes everything with no retention check; `--force` removed; runs for real by default (`--dry-run=1` to preview); generate-style progress output instead of JSON.
- Sharper output: WebP via `cwebp` now encodes from a PNG intermediate (no double lossy encode); Imagick downscales with Lanczos blur 0.89 + light unsharp; JPEG defaults aligned to quality 80.
- Fix: never ship PNG bytes as `.webp` when `cwebp` is missing/fails — fall back to native WebP encode instead.
- `img()` / `picture()` emit a tiny **server-generated** Super Images storage URL as `<img src>` (`delivery.thumbnail`) while full candidates stay in `srcset` / `<source>` — never a signed runtime action for the placeholder.
- Configurable resize sharpness (`policies.geometry.sharpness`) and custom encoder/optimizer CLI arguments (list or key/value maps).

## 5.0.0
- Initial Craft CMS 5 release (Phase 1–2 core engine + delivery).
- Phase 3: Control Panel (dashboard, playground, diagnostics, settings), doctor/cleanup CLI, preview storage namespace, registry/generation lifecycle events.
