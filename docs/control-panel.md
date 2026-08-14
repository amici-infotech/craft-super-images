# Control Panel & Playground

CP is a **client** of the engine. It uses the same Settings / GenerationService / BinaryResolver as CLI and Twig.

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

PHP `config/super-images.php` remains first-class for deployable, version-controlled configuration. The Settings screen shows an overview of the effective model (not a second schema).

---

## Dashboard

Actionable overview:

- selected driver + formats
- optimizer availability
- default storage
- delivery mode
- queue pending/failed (Craft queue)
- links into Playground / Diagnostics

No fake “derivative totals” from a database table.

---

## Encoders & Optimizers

Shows:

- encoder quality config
- optimizer tool selection per format
- resolved binary paths from `optimizers.binaries` / env

Use this to confirm macOS vs Ubuntu path wiring.

---

## Playground

1. Pick an Asset ID
2. Choose profile / variant / format
3. Generate preview

Preview generation:

- calls real `GenerationService`
- writes under `preview/Ymd/...` (not production derivative paths)
- returns size/dimension comparison, % saved, duration, Twig/PHP samples

Clean previews with:

```bash
php craft super-images/cleanup --previews-only --dry-run=1
php craft super-images/cleanup --previews-only --dry-run=0
```

Retention: `cleanup.previewRetentionDays` (default `2`).

---

## Diagnostics

Renders the same doctor checks as:

```bash
php craft super-images/doctor
```

---

## Permissions

Assign in Craft user groups:

- `super-images:view`
- `super-images:playground`
- `super-images:diagnostics`
- `super-images:manage-settings`
