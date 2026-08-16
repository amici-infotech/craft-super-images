# Control Panel & Playground

The Control Panel is a **client of the same engine** as Twig and CLI — same settings model, same Generation Service, same binary resolver.

---

## Navigation

```text
Super Images
├── Dashboard
├── Encoders & Optimizers
├── Playground
├── Diagnostics
└── Settings
```

Deployable truth still lives in `config/super-images.php`. The Settings screen visualizes that model and can edit **derivative naming** when the PHP file does not lock it.

---

## Dashboard

Quick health overview:

- Selected driver + supported formats
- Optimizer binary availability
- Default storage adapter
- Delivery mode (`generateBeforePageLoad`)
- Craft queue pending / failed counts
- Shortcuts into Playground and Diagnostics

No fake “total derivatives” counters from a database table — Super Images does not keep a derivative DB table.

---

## Encoders & Optimizers

Confirms:

- Encoder quality from config
- Which optimizer tool runs per format
- Resolved binary paths + Ubuntu install hints when missing

---

## Playground

1. Pick a Craft Asset  
2. Choose a profile  
3. **Generate profile previews**

Expands that profile’s **variants × formats** and runs the real pipeline.

You get:

- Original summary  
- Grid of results (size, duration, % vs original)  
- Ready-to-copy Twig for the profile  

Files land under `preview/YYYYMMDD/…` so they never collide with production derivatives.

```bash
php craft super-images/cleanup --previews-only --dry-run=1
php craft super-images/cleanup --previews-only --dry-run=0
```

Retention: `cleanup.previewRetentionDays` (default `2`).

---

## Diagnostics

Same checks as:

```bash
php craft super-images/doctor
```

Use this when formats, Imagick, or optimizers look wrong on a server.

---

## Settings

Read-only overview of enabled state, profiles, adapters, encoders, and optimizers.

### Derivative naming

Editable section for path templates:

- **Asset path template** — Craft Asset originals  
- **Local / remote path template** — non-Asset sources  
- **Transform hash length** — how many identity characters go into `{transformHash}`  
- **Include volume in `{folderHash}`**  

Also shows:

- Live example paths  
- Full token glossary  
- Copy-paste `config/super-images.php` snippet  

Why this matters: without a transform/identity token in the asset path, changing operations (sepia, crop, quality, …) can reuse a **stale cached file**. Defaults include `{transformHash}` for that reason.

If `storage.naming` is present in `config/super-images.php`, the form becomes read-only and points you to the PHP file.

Details: [Storage](./storage.md).

---

## Permissions

Assign in Craft user groups:

| Permission | Access |
|---|---|
| `super-images:view` | Dashboard |
| `super-images:playground` | Playground |
| `super-images:diagnostics` | Diagnostics |
| `super-images:manage-settings` | Settings (including naming) |

---

## Related

- [Interactive frontend demo](./demo.md)
- [CLI](./cli.md)
- [Configuration](./configuration.md)
