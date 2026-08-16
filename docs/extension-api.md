# Extension API

Third parties can extend Super Images without editing core files. Register from your plugin `init()` early enough that generation can see the types.

---

## Registries & events

After defaults register, each manager fires a registration event:

| Manager | Event constant | Event class |
|---|---|---|
| `DriverManager` | `EVENT_REGISTER_DRIVERS` | `RegisterDriversEvent` |
| `EncoderManager` | `EVENT_REGISTER_ENCODERS` | `RegisterEncodersEvent` |
| `OptimizerManager` | `EVENT_REGISTER_OPTIMIZERS` | `RegisterOptimizersEvent` |
| `StorageManager` | `EVENT_REGISTER_STORAGE_ADAPTERS` | `RegisterStorageAdaptersEvent` |
| `OperationRegistry` | `EVENT_REGISTER_OPERATIONS` | `RegisterOperationsEvent` |

Built-in operations call driver methods via duck-typing (`invokeDriver`), so a custom driver that implements the same method signatures (e.g. `resize()`, `grayscale()`) works with existing ops. Prefer an explicit `driver` config value in production; under `driver: auto`, newly registered drivers are tried after libvips → imagick → gd.

---

## Custom operation

```php
use amici\SuperImages\events\RegisterOperationsEvent;
use amici\SuperImages\registries\OperationRegistry;
use yii\base\Event;

Event::on(
    OperationRegistry::class,
    OperationRegistry::EVENT_REGISTER_OPERATIONS,
    static function (RegisterOperationsEvent $event): void {
        $event->operations['my-op'] = MyOperation::class;
    }
);
```

Implement `OperationInterface` (or extend `AbstractOperation` and use `$this->invokeDriver($driver, 'myMethod', $handle, …)`).

---

## Custom driver

```php
use amici\SuperImages\events\RegisterDriversEvent;
use amici\SuperImages\registries\DriverManager;
use yii\base\Event;

Event::on(
    DriverManager::class,
    DriverManager::EVENT_REGISTER_DRIVERS,
    static function (RegisterDriversEvent $event): void {
        $event->drivers[] = new MyDriver();
    }
);
```

Implement `ImageDriverInterface`. For built-in operations to run, expose the same transform methods the ops call (`fit`, `crop`, `watermark`, …). Set `'driver' => 'my-driver'` in config (or rely on auto-fallback after built-ins).

---

## Custom encoder

```php
use amici\SuperImages\events\RegisterEncodersEvent;
use amici\SuperImages\registries\EncoderManager;
use yii\base\Event;

Event::on(
    EncoderManager::class,
    EncoderManager::EVENT_REGISTER_ENCODERS,
    static function (RegisterEncodersEvent $event): void {
        // Overwrites per-format keys returned by formats()
        $event->encoders[] = new MyWebpEncoder();
    }
);
```

Implement `EncoderInterface`. Registration maps each `formats()` entry to your encoder.

---

## Custom optimizer

Config selects optimizers by **tool name** (e.g. `'jpeg' => 'jpegoptim'` or `'jpeg' => ['tool' => 'my-tool']`).

```php
use amici\SuperImages\events\RegisterOptimizersEvent;
use amici\SuperImages\registries\OptimizerManager;
use yii\base\Event;

Event::on(
    OptimizerManager::class,
    OptimizerManager::EVENT_REGISTER_OPTIMIZERS,
    static function (RegisterOptimizersEvent $event): void {
        // name() must match the tool string used in config
        $event->optimizers[] = new MyJpegOptimizer(); // name() => 'my-jpeg'
    }
);
```

```php
'optimizers' => [
    'jpeg' => 'my-jpeg', // or ['tool' => 'my-jpeg', …]
],
```

Implement `OptimizerInterface`. If no matching registered optimizer is found, Super Images falls back to the built-in binary optimizer when the CLI tool exists.

---

## Custom storage adapter

### Ready instance

```php
use amici\SuperImages\events\RegisterStorageAdaptersEvent;
use amici\SuperImages\registries\StorageManager;
use yii\base\Event;

Event::on(
    StorageManager::class,
    StorageManager::EVENT_REGISTER_STORAGE_ADAPTERS,
    static function (RegisterStorageAdaptersEvent $event): void {
        $event->adapters['my-cdn'] = new MyCdnAdapter('my-cdn', [/* … */]);
    }
);
```

### Config `type` factory (recommended for deployable config)

```php
Event::on(
    StorageManager::class,
    StorageManager::EVENT_REGISTER_STORAGE_ADAPTERS,
    static function (RegisterStorageAdaptersEvent $event): void {
        $event->types['gcs'] = static function (string $name, array $config) {
            return new GcsStorageAdapter($name, $config);
        };
    }
);
```

```php
'storage' => [
    'adapters' => [
        'gcs' => [
            'type' => 'gcs',
            // …
        ],
    ],
],
```

Implement `StorageAdapterInterface`. Paths are still built by core (`StoragePathBuilder`); adapters only read/write/URL.

---

## Generation lifecycle events

`GenerationService` emits:

| Event | When |
|---|---|
| `EVENT_BEFORE_GENERATE` | Before processing (including cache-hit skip checks) |
| `EVENT_AFTER_GENERATE` | After success or skip |
| `EVENT_BEFORE_ENCODE` | After operations, before encode |
| `EVENT_AFTER_ENCODE` | After encode (see class docs for external-converter nuance) |

Payload: `GenerationEvent` with request / definition / identity / result as available.

Listeners that change processing-significant options must understand **identity** implications (determinism / cache keys).

---

## Contracts

| Interface | Role |
|---|---|
| `ImageDriverInterface` | Load / transform / native encode |
| `EncoderInterface` | Format bytes from a handle |
| `OptimizerInterface` | Post-encode shrink |
| `StorageAdapterInterface` | Persist derivatives + public URLs |
| `OperationInterface` | Named transform step |

Rules:

- Drivers manipulate images; they do not store or build URLs  
- Encoders produce format bytes  
- Optimizers post-process encoded bytes  
- Storage adapters honor deterministic paths from core  
- External binaries only via `ProcessRunner`  
- No credential leakage into logs, markers, or identity  

---

## Related

- [Configuration](./configuration.md)  
- [Storage & naming](./storage.md)  
- [Twig operations](./twig.md)
