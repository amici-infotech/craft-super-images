# Extension API

Third parties can extend Super Images without editing plugin core files.

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

Example (custom operation):

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

Register from your plugin `init()` early enough that generation can see the type.

---

## Generation lifecycle events

`GenerationService` emits:

| Event | When |
|---|---|
| `EVENT_BEFORE_GENERATE` | Before processing begins |
| `EVENT_AFTER_GENERATE` | After success (includes result) |
| `EVENT_BEFORE_ENCODE` | Before encode |
| `EVENT_AFTER_ENCODE` | After encode |

Payload: `GenerationEvent` with request / definition / identity / result context as available.

Listeners that change processing-significant options must understand identity implications (determinism).

---

## Contracts

Implement the existing interfaces:

- `ImageDriverInterface`
- `EncoderInterface`
- `OptimizerInterface`
- `StorageAdapterInterface`
- `OperationInterface`

Rules:

- Drivers manipulate images; they do not store or build URLs
- Encoders produce format bytes
- Optimizers post-process encoded bytes
- Storage adapters honor deterministic paths from core
- External binaries only via `ProcessRunner`
- No credential leakage into logs, markers, or identity

---

## Custom storage adapter sketch

```php
use amici\SuperImages\events\RegisterStorageAdaptersEvent;
use amici\SuperImages\registries\StorageManager;
use yii\base\Event;

Event::on(
    StorageManager::class,
    StorageManager::EVENT_REGISTER_STORAGE_ADAPTERS,
    static function (RegisterStorageAdaptersEvent $event): void {
        $event->adapters['my-cdn'] = new MyCdnAdapter('my-cdn', [
            // adapter config…
        ]);
    }
);
```
