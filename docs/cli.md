# CLI & queue

**Cheat sheet** — full reference below.

| Task | Command |
|---|---|
| Is everything wired up? | `php craft super-images/doctor` |
| What config is active? | `php craft super-images/status` |
| Preview transforms for one asset | `php craft super-images/generate --asset=123 --dry-run=1` |
| Generate one asset now | `php craft super-images/generate --asset=123` |
| Queue a whole volume | `php craft super-images/generate --volume=images --queue=1` |
| After switching storage / stale URLs | `php craft super-images/cleanup --all=1` then regenerate |

All generation uses the same `GenerationService` as runtime Twig and the Control Panel.

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

Console output estimates totals from profile × variant × format counts (no full
`plan()` pass over the volume), then prints each transform as it finishes — not
batched per asset after all variants complete:

```text
Generating ~520 units across 87 assets (6 units/asset estimated).

[asset 1/87] #101 hero.jpg (6 units)
  [1/~520] [generated] responsive/sm.webp → /transforms/super-images/417627…/101/hero-sm.webp
  [2/~520] [already exists] responsive/sm.jpg → /transforms/super-images/…/hero-sm.jpg
  [3/~520] [generated] responsive/md.webp → …

Summary: generated=430 already_exists=88 failed=2 queued=0 units=520 (41.3s)

Failures:
  • #101 hero.jpg — responsive/lg.webp — Source file is missing
```

`[already exists]` means the derivative is already in storage and `--force` wasn't set.

Individual unit failures are listed under **Failures** at the end. The command
exits **0** when the run completes; only bad flags or a disabled plugin return
a non-zero exit code.

Assets are processed in batches of 50. Each asset resolves its source file once
and writes all variants before moving on.

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

Deletes derivative files under the default storage adapter. For **local** storage,
files are found by scanning the adapter root. For **remote** adapters (S3, Spaces,
R2, etc.), cleanup walks the per-asset index at `@storage/super-images/asset-index`
and deletes each indexed object from its adapter — buckets are not listed directly.

With `--all=1`, cleanup also wipes **existence markers** (`@storage/super-images/markers`)
and the asset index so the next generate pass treats every transform as missing
(useful after switching storage backends).

**Runs for real by default.** Pass `--dry-run=1` to preview without deleting.

| Mode | How | Retention |
|---|---|---|
| **Aged** (default) | Every transform older than retention | `cleanup.generatedRetentionDays` (default 365), overridable with `--retention-days` |
| **All** | `--all=1` | None — deletes everything immediately |
| **Asset** | `--asset=ID` | N/A — deletes that asset’s indexed derivatives |
| **Orphaned** | `--orphaned=1` | Same as aged, via index `updatedAt` |

```bash
# Delete aged transforms (older than generatedRetentionDays).
php craft super-images/cleanup

# Preview only — list what would be deleted.
php craft super-images/cleanup --dry-run=1

# Temporary retention for this run only (e.g. older than 7 days).
php craft super-images/cleanup --retention-days=7

# Nuclear: delete every derivative now, ignore retention, clear index + markers.
php craft super-images/cleanup --all=1

# One asset’s indexed derivatives.
php craft super-images/cleanup --asset=123

# Indexed derivatives whose Craft asset no longer exists.
php craft super-images/cleanup --orphaned=1
```

| Option | Alias | Type | Default | Meaning |
|---|---|---|---|---|
| `--dry-run` | `-d` | bool | `false` | List matches without deleting. |
| `--asset` | `-a` | int\|null | `null` | Purge all indexed derivatives for one Craft asset ID. |
| `--orphaned` | | bool | `false` | Purge derivatives for indexed assets that no longer exist in Craft. Subject to retention. |
| `--all` | | bool | `false` | Delete every derivative immediately (no retention check). Clears the asset index and existence markers. Local: scans adapter root. Remote: uses asset index. |
| `--retention-days` | | int\|null | `null` | Temporary override of `cleanup.generatedRetentionDays` for aged / orphaned modes. Ignored when `--all=1`. |

Mode precedence: `--asset` › `--orphaned` › `--all` › aged (default).

Output matches the generate CLI style:

```text
Cleaning aged transforms (retention: 30 days)…

  [1/18] [deleted] 417627…/101/hero-sm.webp
  [2/18] [deleted] 417627…/101/hero-md.webp
  ...

Summary: deleted=18 kept=412 failed=0 markers=42 indexes=3 (1.2s)
```

Non-zero `failed` sets the exit code to `ExitCode::UNSPECIFIED_ERROR`, so cron
jobs can alert on partial failures.

---

## Cache & retention

Generated derivatives are meant to be cached for the long haul — regenerating
on every deploy defeats the purpose of eager generation. Two independent knobs
control this:

```php
'cleanup' => [
    // Playground preview artifacts (used by preview-only helpers).
    'previewRetentionDays' => 2,
    // Default / orphaned CLI sweeps only delete files older than this many days.
    // Override per run with --retention-days. Use --all=1 to ignore entirely.
    'generatedRetentionDays' => 365,
    'allowRemoteScan' => false,
],
```

`generatedRetentionDays` does **not** block the immediate, automatic cleanup
that runs when a Craft Asset is actually deleted or its file replaced (see
[`policies.cleanup`](policies.md) — those always fire right away since the
source itself is gone or has changed). It only guards the default aged and
`--orphaned` console sweeps. `--all=1` bypasses it.

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
