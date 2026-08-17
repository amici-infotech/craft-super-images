# Super Images — extension examples

Copy these into **your own Craft plugin** and adjust the namespace. They are reference implementations, not loaded automatically.

## Quick map

| You want to… | Start here |
|---|---|
| Register everything from one place | [`ExtensionPlugin.php`](ExtensionPlugin.php) |
| Store files on a custom CDN / API | [`storage/ExampleStorageAdapter.php`](storage/ExampleStorageAdapter.php) |
| Replace or wrap the encode step | [`encoders/ExampleWebpEncoder.php`](encoders/ExampleWebpEncoder.php) |
| Add a post-encode optimizer | [`optimizers/ExampleOptimizer.php`](optimizers/ExampleOptimizer.php) |
| Add a Twig `operations` step | [`operations/ExampleTintOperation.php`](operations/ExampleTintOperation.php) |

## Install in your plugin

1. Copy the files you need into e.g. `plugins/my-plugin/src/superimages/`.
2. Wire registrations in your plugin `init()` (see `ExtensionPlugin.php`).
3. For storage, add an adapter block to the project's `config/super-images.php`:

```php
'storage' => [
    'adapters' => [
        'acme' => [
            'type' => 'acme',           // must match $event->types key
            'baseUrl' => 'https://cdn.example.com',
            'apiKey' => App::env('ACME_CDN_KEY'),
        ],
    ],
],
```

4. Run `php craft super-images/doctor` and generate one test asset.

## Docs

- [Extension API](../docs/extension-api.md) — events, contracts, config wiring
- [Storage](../docs/storage.md) — local, S3, R2, markers, naming
- [Encoders & optimizers](../docs/encoders-optimizers.md) — binary tools, `optimizeType`
