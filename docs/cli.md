# CLI & queue

All CLI generation calls the same `GenerationService` as runtime and Playground, so
what you generate from the console is byte-for-byte what Twig/runtime would produce
for the same asset/profile/variant/format.

Every command accepts Yii's built-in `--color=0|1`, `--interactive=0|1`, and `--help`/`-h`
options in addition to what's documented below. Boolean/int flags can be passed as
`--flag` (implies `1`), `--flag=1`, or `--flag=0`. Option names accept either
`camelCase` or `kebab-case` on the command line (e.g. `--dryRun` and `--dry-run` are
equivalent).

---

## Status

```bash
php craft super-images/status
```

No options. Prints a JSON snapshot: `enabled`, `deliveryMode`, `storageDefault`,
`autoGenerate` config, `runtime` (enabled + urlTtl), the selected driver and the
formats it supports, and `queuePending` (pending Craft queue job count, if the
queue table exists).

Use this first when something looks off — it tells you which driver was actually
selected (`auto` can silently fall back to GD if Imagick/libvips are missing) and
whether the runtime lazy-generation endpoint is enabled.

---

## Config

```bash
# Dump the fully-resolved plugin configuration.
php craft super-images/config

# Also include a manifest sample (first 5 units) for one asset.
php craft super-images/config --asset=123
```

| Option | Type | Default | Meaning |
|---|---|---|---|
| `--asset` | int | *(none)* | When set, includes `asset.manifestUnitCount` and up to 5 sample `profile`/`variant`/`format`/`identity`/`publicUrl` units for that Craft asset ID. Errors with exit code `DATAERR` if the asset doesn't exist. |

Use this to sanity-check what a specific asset would generate before running
`generate` against it, or to confirm a config file change (e.g. a new profile,
storage adapter, or policy) actually took effect after a cache clear.

---

## Generate

Eagerly generates derivatives (profile × variant × format units) for one asset,
a whole volume, or both, either inline or via the Craft queue.

```bash
# Preview exactly what would be generated for one asset, without writing anything.
php craft super-images/generate --asset=123 --dry-run=1

# Generate every unit for one asset right now (inline, in this process).
php craft super-images/generate --asset=123

# Generate only the "md" variant / "webp" format for one asset.
php craft super-images/generate --asset=123 --variant=md --format=webp

# Regenerate even if the derivative already exists on disk (e.g. after a
# policy/encoder change that should apply to already-generated images).
php craft super-images/generate --asset=123 --force=1

# Generate every image asset in a volume, capped at the first 50 matches.
php craft super-images/generate --volume=images --limit=50

# Generate a volume's "hero" profile only, via the Craft queue instead of inline
# (recommended for anything beyond a handful of assets — see "Auto-generate" below).
php craft super-images/generate --volume=images --profile=hero --queue=1

# Combine asset + volume filters, restrict to one profile/variant/format, and
# force regeneration, queued.
php craft super-images/generate --volume=images --profile=responsive --variant=lg --format=avif --force=1 --queue=1
```

| Option | Alias | Type | Default | Meaning |
|---|---|---|---|---|
| `--asset` | | int\|null | `null` | Generate for a single Craft asset ID. At least one of `--asset`/`--volume` is required. |
| `--volume` | | string\|null | `null` | Generate for every image asset (`kind('image')`, any status) in this Craft volume handle. Combine with `--asset` to further narrow. |
| `--profile` | | string\|null | `null` | Restrict to one profile handle from `config/super-images.php` `profiles`. |
| `--variant` | | string\|null | `null` | Restrict to one variant handle within the selected profile(s). |
| `--format` | | string\|null | `null` | Restrict to one output format (e.g. `jpg`, `webp`, `avif`). |
| `--dry-run` | `-d` | bool | `false` | Plan and print units without generating or touching storage. Ignores `--queue`. |
| `--queue` | `-q` | bool | `false` | Push one `GenerateAssetJob` per matching asset onto the Craft queue instead of generating inline. Requires a running queue worker (`php craft queue/listen` or `queue/run`). |
| `--force` | `-f` | bool | `false` | Regenerate and overwrite even when a derivative already exists at its storage path. |
| `--limit` | | int | `0` | Cap the number of matching **assets** processed (`0` = no limit). Applied before unit expansion, so it limits assets, not units. |

Console output shows a plan summary, then progresses per asset and per unit with
a running counter, e.g.:

```text
Planned 520 units across 87 assets.

[asset 1/87] #101 hero.jpg (6 units)
  [1/520] [generated] responsive/sm.jpg → /uploads/super-images/68/fc/68fcc0…--responsive-sm.jpg
  [2/520] [skipped] responsive/sm.webp
  ...

Summary: generated=430 skipped=88 failed=2 queued=0 (41.3s)
```

`[skipped]` means the derivative already exists and `--force` wasn't set.
`[failed]` prints the exception message and makes the command exit non-zero
(`ExitCode::UNSPECIFIED_ERROR`) so it's easy to catch in CI/cron.

---

## Doctor

```bash
# Human-readable PASS/WARN/FAIL report with fix hints.
php craft super-images/doctor

# Same checks, machine-readable JSON (exit code reflects fail count either way).
php craft super-images/doctor --json=1
```

| Option | Type | Default | Meaning |
|---|---|---|---|
| `--json` | int (bool) | `0` | Emit a JSON report instead of the formatted console table. Useful for monitoring/alerting. |

Checks cover: which image driver is active and its supported formats, whether
optimizer binaries (`jpegoptim`, `cwebp`, `avifenc`, …) resolve on `$PATH`,
storage adapter reachability, existence-marker path writability, temp directory
writability, signed-URL secret configuration, and Craft queue availability.
Exits non-zero when any check fails, so it's safe to wire into a deploy pipeline:

```bash
php craft super-images/doctor --json=1 || exit 1
```

---

## Cleanup

Deletes derivative files. **Always dry-runs by default** — nothing is deleted
unless you pass both `--dry-run=0` and `--force=1`. Pick a mode with `--asset`,
`--orphaned`, or `--all`; with none of those, it defaults to Playground preview
cleanup only.

Generated (non-preview) derivatives are protected by `cleanup.generatedRetentionDays`
in config (**default: 365 days** — see [Cache & retention](#cache--retention)
below), so `--orphaned` and `--all` never touch anything younger than a year
unless you explicitly lower that via config or `--retention-days`.

```bash
# Default mode: preview-only. See what Playground previews older than
# cleanup.previewRetentionDays (default 2 days) would be deleted.
php craft super-images/cleanup

# Actually delete them.
php craft super-images/cleanup --dry-run=0 --force=1

# Override the preview retention window for this run only (7 days instead of config default).
php craft super-images/cleanup --retention-days=7 --dry-run=0 --force=1

# Purge every derivative for one asset (e.g. before manually re-uploading a
# replacement file outside of Craft's normal replace flow).
php craft super-images/cleanup --asset=123 --dry-run=0 --force=1

# Purge derivatives whose Craft asset was hard-deleted and therefore never
# fired Super Images' normal asset-delete cleanup hook. Respects the 1-year
# generatedRetentionDays safety net by default.
php craft super-images/cleanup --orphaned=1
php craft super-images/cleanup --orphaned=1 --dry-run=0 --force=1

# Same, but ignore the retention safety net entirely for this run (retentionDays=0).
php craft super-images/cleanup --orphaned=1 --retention-days=0 --dry-run=0 --force=1

# Nuclear option: wipe every generated derivative (not previews) so the next
# `generate` run rebuilds everything from scratch — typically run once right
# after a profile/geometry/encoder config change makes old output obsolete.
php craft super-images/cleanup --all=1 --dry-run=0 --force=1
```

| Option | Alias | Type | Default | Meaning |
|---|---|---|---|---|
| `--dry-run` | `-d` | bool | `true` | Report candidates without deleting. Set `0` (with `--force=1`) to actually delete. |
| `--force` | `-f` | bool | `false` | Required alongside `--dry-run=0` to confirm deletion. Deletion is refused (exit `DATAERR`) otherwise. |
| `--asset` | `-a` | int\|null | `null` | Purge all indexed derivatives for one Craft asset ID via {@see AssetDerivativeIndex}, then clear its index. |
| `--orphaned` | | bool | `false` | Purge derivatives for indexed assets that no longer exist in Craft (hard-deleted). Subject to `cleanup.generatedRetentionDays`. |
| `--all` | | bool | `false` | Purge every generated derivative under the default local storage adapter, excluding `preview/`. Subject to `cleanup.generatedRetentionDays`. Local adapters only. |
| `--previews-only` | | int | `1` | Legacy no-op kept for backwards compatibility — preview cleanup is already the default mode when no other mode flag is set. |
| `--retention-days` | | int\|null | `null` | Overrides `cleanup.previewRetentionDays` (default mode) or `cleanup.generatedRetentionDays` (`--orphaned`/`--all`) for this run only. |

Mode precedence when multiple mode flags are passed at once: `--asset` › `--orphaned` › `--all` › preview cleanup (default).

Output is a JSON report, e.g.:

```json
{
    "dryRun": false,
    "retentionDays": 365,
    "cutoff": 1723497600,
    "assetsScanned": 412,
    "assetsOrphaned": 3,
    "assetsSkippedFresh": 0,
    "candidates": 18,
    "deleted": 18,
    "errors": 0,
    "paths": [...],
    "pathsTruncated": false
}
```

Non-zero `errors` sets the exit code to `ExitCode::UNSPECIFIED_ERROR`, so cron
jobs can alert on partial failures.

---

## Cache & retention

Generated derivatives are meant to be cached for the long haul — regenerating
on every deploy defeats the purpose of eager generation. Two independent knobs
control this:

```php
'cleanup' => [
    // Playground preview artifacts (short-lived, disposable experiments).
    'previewRetentionDays' => 2,
    // Real generated derivatives — protected from `--orphaned`/`--all` cleanup
    // until they're at least this old. Defaults to a full year; raise or
    // lower to match your storage budget.
    'generatedRetentionDays' => 365,
    'allowRemoteScan' => false,
],
```

`generatedRetentionDays` does **not** block the immediate, automatic cleanup
that runs when a Craft Asset is actually deleted or its file replaced (see
[`policies.cleanup`](policies.md) — those always fire right away since the
source itself is gone or has changed). It only guards the bulk `--orphaned`
and `--all` console sweeps against deleting derivatives that are simply
"unused for now" but still well within your caching window.

---

## Auto-generate on Asset save

```php
'autoGenerate' => [
    'enabled' => true,
    'onUpload' => true,
    'onReplace' => true,
    'onFocalPointChange' => true,
    'queue' => true,
    'disableDuringImport' => true,
],
```

Volume override:

```php
'volumes' => [
    'images' => [
        'autoGenerate' => true,
        'profile' => 'responsive',
    ],
],
```

Jobs are Craft queue jobs (`GenerateAssetJob`). Run the queue worker in production:

```bash
php craft queue/listen
```

---

## Manifest

For an Asset + profile, the Manifest expands **variants × formats** into generation
units. CLI (`generate`, `config --asset`) and auto-generate both iterate those units.
