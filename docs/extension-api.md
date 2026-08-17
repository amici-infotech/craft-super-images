# Extension API

Extend Super Images from **your own Craft plugin** without forking core.

**Runnable starters:** [`examples/`](../examples/README.md) — storage adapter, optimizer, operation, and a sample `init()` wiring.

---

## How registration works

On plugin boot, Super Images registers built-in drivers, encoders, optimizers, operations, and storage types. Each registry then fires an event you can listen to:

| Registry | Event constant | Add via |
|---|---|---|
| Drivers | `DriverManager::EVENT_REGISTER_DRIVERS` | `$event->drivers[]` |
| Encoders | `EncoderManager::EVENT_REGISTER_ENCODERS` | `$event->encoders[]` |
| Optimizers | `OptimizerManager::EVENT_REGISTER_OPTIMIZERS` | `$event->optimizers[]` |
| Operations | `OperationRegistry::EVENT_REGISTER_OPERATIONS` | `$event->operations['handle'] = Class::class` |
| Storage | `StorageManager::EVENT_REGISTER_STORAGE_ADAPTERS` | `$event->types['type'] = factory` or `$event->adapters['handle'] = instance` |

Register in your plugin `init()` **before** generation runs (normal Craft plugin init order is fine).

See [`examples/ExtensionPlugin.php`](../examples/ExtensionPlugin.php) for a complete wiring template.

---

## Custom storage adapter

Implement `StorageAdapterInterface`. Super Images builds paths; you read/write/delete and return public URLs.

**Recommended:** config `type` factory (deployable via `config/super-images.php`):

```php
use amici\SuperImages\events\RegisterStorageAdaptersEvent;
use amici\SuperImages\registries\StorageManager;
use yii\base\Event;

Event::on(StorageManager::class, StorageManager::EVENT_REGISTER_STORAGE_ADAPTERS,
    static function (RegisterStorageAdaptersEvent $event): void {
        $event->types['acme'] = static fn(string $name, array $config) => new \myagency\AcmeStorageAdapter($name, $config);
    },
);
```

```php
// config/super-images.php
'storage' => [
    'adapters' => [
        'acme' => ['type' => 'acme', 'baseUrl' => 'https://cdn.example.com', /* … */],
    ],
],
```

**Copy-paste base:** [`examples/storage/ExampleStorageAdapter.php`](../examples/storage/ExampleStorageAdapter.php)

For S3-compatible APIs, study `src/storage/S3CompatibleStorageAdapter.php` instead of starting from scratch.

---

## Custom optimizer

Implement `OptimizerInterface`. Your `name()` must match the tool string in config:

```php
'optimizers' => [
    'jpeg' => 'example-jpeg',  // matches ExampleOptimizer::name()
],
```

**Copy-paste base:** [`examples/optimizers/ExampleOptimizer.php`](../examples/optimizers/ExampleOptimizer.php)

Use `ProcessRunner` for CLI tools — never shell out manually. If no registered optimizer matches, Super Images falls back to the built-in binary optimizer when the CLI exists.

---

## Custom operation

Implement `OperationInterface` or extend `AbstractOperation`:

```php
$event->operations['tint'] = \myagency\ExampleTintOperation::class;
```

Twig:

```twig
{{ craft.superImages.img(asset, {
  operations: [{ type: 'tint', color: '#0066cc', opacity: 0.15 }],
  format: 'jpg',
}) }}
```

Built-in operations call driver methods via duck typing (`invokeDriver`). Custom drivers work if they expose the same method names (`resize`, `grayscale`, …).

**Copy-paste base:** [`examples/operations/ExampleTintOperation.php`](../examples/operations/ExampleTintOperation.php)

---

## Custom driver

Implement `ImageDriverInterface`. Register with `RegisterDriversEvent`. Set `'driver' => 'my-driver'` in config, or rely on `auto` fallback after libvips → imagick → gd.

Drivers manipulate pixels only — no storage URLs, no encoding to final formats unless via `encodeNative()`.

---

## Custom encoder

Implement `EncoderInterface` and register with `RegisterEncodersEvent`. Encoders are selected **by output format** — registering a WebP encoder replaces the built-in native encoder for `webp` while your plugin is loaded.

Most projects should keep the native encoder and set `optimizers.webp = 'cwebp'`. Custom encoders are for specialized pipelines (proprietary SDK, GPU farm, etc.).

```php
Event::on(EncoderManager::class, EncoderManager::EVENT_REGISTER_ENCODERS,
    static function (RegisterEncodersEvent $event): void {
        $event->encoders[] = new \myagency\ExampleWebpEncoder();
    },
);
```

**Copy-paste base:** [`examples/encoders/ExampleWebpEncoder.php`](../examples/encoders/ExampleWebpEncoder.php) — PNG intermediate → `cwebp` → WebP, with native fallback.

---

## Generation lifecycle events

`GenerationService` emits hooks for analytics or side effects:

| Event | When |
|---|---|
| `EVENT_BEFORE_GENERATE` | Before processing (including cache-hit checks) |
| `EVENT_AFTER_GENERATE` | After success or skip |
| `EVENT_BEFORE_ENCODE` | After operations, before encode |
| `EVENT_AFTER_ENCODE` | After encode |

Payload: `GenerationEvent` with request, definition, identity, and result when available.

Changing identity-affecting options in listeners can alter cache keys — treat identity as part of your public contract.

---

## Contracts (quick reference)

| Interface | Responsibility |
|---|---|
| `ImageDriverInterface` | Load, transform, native encode |
| `EncoderInterface` | Handle → format bytes |
| `OptimizerInterface` | Post-encode shrink |
| `StorageAdapterInterface` | Persist + public URL |
| `OperationInterface` | One named transform step |

Rules: drivers don't store files; encoders don't build URLs; external binaries only via `ProcessRunner`; never log credentials or secrets into markers/identity.

---

## Related

- [Getting started](./getting-started.md)
- [Storage](./storage.md)
- [Encoders & optimizers](./encoders-optimizers.md)
- [Examples](../examples/README.md)
