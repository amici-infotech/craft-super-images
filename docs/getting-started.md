# Getting started

Super Images generates optimized image derivatives (WebP, AVIF, resized JPGs, etc.) and serves them from your storage adapter — local disk, S3, DigitalOcean Spaces, or Cloudflare R2.

**You do not need to understand the whole pipeline on day one.** Start with Twig, then add storage and CLI when you deploy.

---

## 1. Install

```bash
composer require amici/craft-super-images
php craft plugin/install super-images
cp vendor/amici/craft-super-images/config/super-images.example.php config/super-images.php
```

Check the install:

```bash
php craft super-images/doctor
php craft super-images/status
```

Doctor flags missing drivers and binaries with Ubuntu install hints. Full step-by-step install + FAQs for GD, Imagick, and libvips (including FPM / nginx 502): [Drivers](./drivers.md).

---

## 2. Output your first image

In any Twig template:

```twig
{{ craft.superImages.img(entry.heroImage.one(), {
  profile: 'responsive',
  variant: 'md',
  format: 'webp',
  alt: entry.title,
}) }}
```

With default settings, missing files are **generated during the page request** and the `<img>` gets a CDN/storage URL.

---

## 3. Choose where files live

**Local (simplest for dev):**

```php
// config/super-images.php
'storage' => [
    'default' => 'local',
    'adapters' => [
        'local' => [
            'type' => 'local',
            'path' => '@webroot/transforms/super-images',
            'baseUrl' => '@web/transforms/super-images',
        ],
    ],
],
```

**Cloudflare R2 / S3 / Spaces:** see [Storage](./storage.md) — set `baseUrl` to the hostname that actually serves the bucket.

Tip: use `.env` for secrets (`SUPER_IMAGES_STORAGE`, `CF_R2_*`, etc.).

---

## 4. Pre-generate for production

Don't rely on first page hit in production if you have many transforms:

```bash
# Preview what would run
php craft super-images/generate --volume=images --dry-run=1

# Queue generation (recommended)
php craft super-images/generate --volume=images --queue=1

# Or generate inline
php craft super-images/generate --volume=images
```

---

## 5. Try the interactive demo

```bash
cp -R vendor/amici/craft-super-images/demo templates/super-images
```

Open `/super-images` — geometry, formats, delivery modes, copy-paste Twig snippets.

---

## Common questions

**Where are transforms stored?**  
On the configured adapter (`local`, `r2`, `spaces`, …). Not in Craft's `assets` volume unless you point an adapter there.

**Libvips errors about `libvips.so` / “Unable to open library” / nginx 502 on generate?**  
The PHP binding can be present while the native library or FFI is missing — or FPM may SIGSEGV on in-process FFI. With `driver => auto`, Super Images falls back to Imagick, then GD. Ubuntu / Herd install steps and FAQs: [Drivers](./drivers.md).

**Why is my CDN URL 404 but CLI says generated?**  
`baseUrl` must match the bucket you're uploading to. After switching adapters, run `php craft super-images/cleanup --all=1` and regenerate.

**Why are cache hits slow (~300 ms)?**  
Usually a remote HEAD runs when existence markers don't match. Run cleanup + regenerate once; markers should make cache hits ~0–10 ms. See [Storage — markers](./storage.md#existence-markers).

**Custom CDN or optimizer?**  
Copy the starter classes in [`examples/`](../examples/README.md).

---

## Next reads

| Topic | Doc |
|---|---|
| All config keys | [Configuration](./configuration.md) |
| Install GD / Imagick / libvips | [Drivers](./drivers.md) |
| Doctor & cleanup | [Diagnostics](./diagnostics.md) |
| Twig API | [Twig](./twig.md) |
| CLI commands | [CLI](./cli.md) |
| R2 / Spaces setup | [Storage](./storage.md) |
| Extend the plugin | [Extension API](./extension-api.md) + [examples](../examples/README.md) |
