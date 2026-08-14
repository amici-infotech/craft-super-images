# CLI & queue

All CLI generation calls the same `GenerationService` as runtime and Playground.

---

## Commands

### Status

```bash
php craft super-images/status
```

JSON overview: enabled, delivery mode, storage, autoGenerate, runtime, selected driver/formats, queue pending.

### Config

```bash
php craft super-images/config
php craft super-images/config --asset=123
```

Shows resolved settings and (with `--asset`) a manifest sample for that Asset.

### Generate

```bash
php craft super-images/generate --asset=123 --dry-run
php craft super-images/generate --asset=123 --format=webp --variant=md
php craft super-images/generate --asset=123 --force
php craft super-images/generate --volume=images --queue=1
php craft super-images/generate --asset=123 --format=jpg --variant=sm --queue=1
```

Useful flags:

| Flag | Meaning |
|---|---|
| `--dry-run` / `-d` | Plan only |
| `--force` / `-f` | Regenerate even if exists |
| `--queue=1` | Push `GenerateAssetJob` instead of inline |
| `--asset` | Asset ID |
| `--volume` | Volume handle |
| `--format` / `--variant` / `--profile` | Filters |

### Doctor

```bash
php craft super-images/doctor
```

PASS / WARN / FAIL checks for drivers, formats, optimizer binaries, storage, markers, temp, signing, queue.

### Cleanup

```bash
php craft super-images/cleanup --dry-run=1
php craft super-images/cleanup --previews-only --dry-run=0
```

Default is dry-run friendly. Preview cleanup only deletes under the `preview/` storage namespace (Playground artifacts), subject to `cleanup.previewRetentionDays`.

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

For an Asset + profile, the Manifest expands **variants × formats** into generation units. CLI and auto-generate iterate those units.
