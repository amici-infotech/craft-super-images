# Release Notes for Super Images

## 5.0.1
- CLI `generate` starts instantly: totals are estimated from profile × variant × format counts instead of running `plan()` for every derivative up front.
- Storage paths now follow Imager-X style: `{folderHash}/{assetId}/{basename}-{variant}.{ext}`.
- Bulk generation reuses one resolved source per asset (local volumes no longer `getCopyOfFile()` per variant), batches derivative-index writes, and defers temp cleanup until the asset is done.
- CLI `cleanup` default deletes **aged** transforms (by `generatedRetentionDays`); `--all=1` wipes everything with no retention check; `--force` removed; runs for real by default (`--dry-run=1` to preview); generate-style progress output instead of JSON.
- Sharper output: WebP via `cwebp` now encodes from a PNG intermediate (no double lossy encode); Imagick downscales with Lanczos blur 0.89 + light unsharp; JPEG defaults aligned to quality 80.
- Fix: never ship PNG bytes as `.webp` when `cwebp` is missing/fails — fall back to native WebP encode instead.

## 5.0.0
- Initial Craft CMS 5 release (Phase 1–2 core engine + delivery).
- Phase 3: Control Panel (dashboard, playground, diagnostics, settings), doctor/cleanup CLI, preview storage namespace, registry/generation lifecycle events.
