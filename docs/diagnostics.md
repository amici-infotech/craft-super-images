# Cleanup & diagnostics

There is **no** GeneratedImage database table. Cleanup is conservative and explicit.

---

## Doctor

```bash
php craft super-images/doctor
php craft super-images/doctor --json=1
```

Checks are grouped:

```text
Core · Drivers · Optimizers · Storage & paths · Delivery · Queue
```

Including:

- plugin enabled
- GD / Imagick / Libvips **really usable** in this SAPI (not just “extension mentioned”)
  - GD: `gd` + `imagecreatetruecolor`
  - Imagick: extension loads **and** `new Imagick()` works
  - Libvips: php-vips + FFI + native library (and under FPM: `vips` CLI / PHP worker)
- specific **why unavailable** detail + apt/install suggestion when a driver fails
- FFI + Libvips FPM isolation status
- Imagick + Libvips dual-driver check
- fail when **no** driver is usable at all
- warn when `driver` is pinned to an unusable preference (with fallback name)
- selected driver + supported formats
- optimizer binaries (each on its own row with resolved path)
- local storage / markers / temp writable
- runtime signing when generate-before-page-load is false
- Craft queue pending / failed / reserved counts

Missing drivers and binaries show Ubuntu `apt` install hints. CP → **Diagnostics** shows the same layout (pass / warn / fail counts + grouped table). Full driver install FAQs live in [Drivers](./drivers.md).

Statuses: `pass` / `warn` / `fail`.

### Optimizer status

A binary **not found on PATH** is **WARN**, not pass — even when the format still works via native encoding.

### Overall health

| Condition | Badge |
|---|---|
| Any check `fail` | Needs attention |
| Queue has failed jobs | Warnings |
| Warnings only (unused drivers, dual tip, FPM `ffi.enable` off while `vips` CLI works, optional optimizers, …) | **Healthy** |

**Fail when it matters:** preferred `driver => …` is unusable; Libvips is selected/preferred but FPM isolation cannot run (no `vips` binary and no PHP worker); no driver works at all.

`driver => auto` showing **Libvips** on the dashboard is expected — that is the resolved selection, not a pinned preference.

Missing optional optimizers do not block transforms; native driver encoders still produce output.

### Queue

Craft’s built-in queue is used for auto-generate and deferred post-optimize. Keep a queue worker running in production (`php craft queue/listen` or your host’s equivalent). Doctor reports pending / failed / reserved counts when the queue table exists.

Also available in CP → **Super Images → Diagnostics**.

---

## Cleanup

```bash
# Aged production derivatives (uses cleanup.generatedRetentionDays)
php craft super-images/cleanup --dry-run=1
php craft super-images/cleanup --retention-days=7 --dry-run=1

# Playground previews only
php craft super-images/cleanup --previews-only --dry-run=1
php craft super-images/cleanup --previews-only --dry-run=0

# One asset’s Super Images derivatives
php craft super-images/cleanup --asset=123 --dry-run=1

# Orphaned indexed files
php craft super-images/cleanup --orphaned=1 --dry-run=1

# Everything under the Super Images storage prefix (no retention)
php craft super-images/cleanup --all=1 --dry-run=1
```

### Safe by design

May remove:

- abandoned Playground files under `preview/` older than retention
- indexed derivatives you explicitly target (`--asset`, `--orphaned`, `--all`, aged sweep)

Must not remove:

- Craft Volume originals
- unknown files outside Super Images prefixes
- production derivatives solely because “usage this second” cannot be proven

### Config

```php
'cleanup' => [
    'previewRetentionDays' => 2,
    'generatedRetentionDays' => 365,
    'allowRemoteScan' => false,
],
```

Remote full scans are off by default (API cost / foot-gun).

---

## Status without false precision

Prefer:

```bash
php craft super-images/status
php craft super-images/config --asset=123
```

over dashboards that invent library-wide derivative counts.

`status` tells you which driver was **actually** selected (`auto` can fall back to GD if Imagick/libvips are missing).

---

## Related

- [CLI](./cli.md)
- [Drivers](./drivers.md)
- [Policies](./policies.md)
- [Control Panel](./control-panel.md)
