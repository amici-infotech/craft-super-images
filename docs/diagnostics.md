# Cleanup & diagnostics

There is **no** GeneratedImage database table. Cleanup is conservative and explicit.

---

## Doctor

```bash
php craft super-images/doctor
php craft super-images/doctor --json=1
```

Output is grouped into sections:

```text
Core · Drivers · Optimizers · Storage & paths · Delivery · Queue
```

Each optimizer binary is listed on its own line with the resolved path (and which format uses it, when assigned). Use `--json=1` for machine-readable output.

- plugin enabled
- GD / Imagick / Libvips availability
- selected driver formats
- optimizer binaries (via `BinaryResolver`)
- local storage writable
- markers path writable
- temp writable
- runtime signing ready when generateBeforePageLoad is false
- generateBeforePageLoad
- queue counts

Statuses: `PASS` / `WARN` / `FAIL`.

Also available in CP → Diagnostics.

---

## Cleanup

```bash
php craft super-images/cleanup --dry-run=1
php craft super-images/cleanup --previews-only --dry-run=0
```

### Safe by design

May remove:

- abandoned Playground files under `preview/` older than retention

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

over dashboards that invent exact library-wide derivative counts.
