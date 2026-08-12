# Phase 3 — Extension API

## Purpose

Super Images should be an extensible image infrastructure platform.

Third parties must be able to add capabilities without modifying plugin core files.

---

## 1. Extension Goals

Allow registration of:

- Image Drivers
- Encoders
- Optimizers
- Storage Adapters
- Operations
- optional recipe/providers where appropriate

Provide Craft/Yii-style events around the generation lifecycle.

---

## 2. Registries

Stable registries/managers:

```text
DriverManager / DriverRegistry
EncoderManager / EncoderRegistry
OptimizerManager / OptimizerRegistry
StorageManager / StorageRegistry
OperationRegistry
```

Registration should occur through plugin init hooks/events.

Example conceptual pattern:

```php
Event::on(
    OperationRegistry::class,
    OperationRegistry::EVENT_REGISTER_OPERATIONS,
    function(RegisterOperationsEvent $event) {
        $event->operations['my-op'] = MyOperation::class;
    }
);
```

Exact event class names can follow Craft conventions.

---

## 3. Lifecycle Events

Potential events:

```text
beforeProcess / afterProcess
beforeEncode / afterEncode
beforeOptimize / afterOptimize
beforeStore / afterStore
beforeGenerate / afterGenerate
```

Event payloads should expose useful context:

```text
asset identity
generation identity
definition/request
selected driver/encoder/optimizer/storage
result/metrics on after* events
```

Listeners must not be required to break determinism casually.

If a listener changes processing-significant options, identity implications must be understood/documented.

---

## 4. Custom Operation Contract

A custom operation must:

- declare a stable name;
- validate/normalize options;
- declare capability needs;
- apply via driver or safe internal implementation;
- avoid storage/URL/CLI concerns;
- participate in identity via normalized options.

---

## 5. Custom Storage Adapter Contract

Must implement StorageAdapterInterface methods:

```text
write / exists / delete / url / capabilities
```

Must honor:

- deterministic paths provided by core;
- no credential leakage;
- temp vs permanent boundary.

---

## 6. Custom Driver / Encoder / Optimizer Contracts

Must honor Phase 1 responsibility boundaries:

```text
Driver = manipulate image
Encoder = produce format bytes
Optimizer = optimize encoded bytes
```

Must expose capability metadata for diagnostics/CP.

---

## 7. Determinism Rules for Extensions

Extensions that affect output bytes must be identity-visible.

Examples:

- custom sharpen algorithm version
- custom encoder flags
- custom watermark resolver result identity

Do not allow hidden non-deterministic behavior (random dithering seeds, current timestamps) unless explicitly excluded from permanent derivative mode.

---

## 8. Documentation Requirements

Extension docs should include:

- how to register each extension type;
- minimal example plugins;
- event catalog;
- identity/security guidelines;
- testing recommendations.

---

## 9. Security

Third-party extensions are trusted code in PHP terms, but core must still:

- not blindly execute extension-provided shell strings;
- keep ProcessRunner as the safe boundary for binaries;
- validate untrusted runtime input before it reaches extensions where possible.

---

## 10. Testing Requirements

- register mock operation/storage/encoder
- appear in registries
- participate in generation pipeline
- events fire in order
- identity changes when extension options change

---

## 11. Definition of Done

- [ ] Registration events/APIs exist for all extension types
- [ ] Lifecycle events exist
- [ ] Example extension snippets documented
- [ ] CP/diagnostics can discover registered extensions
- [ ] Tests cover registration + event flow

---

## Final Rule

**New capabilities should plug into registries and events. They should not fork the pipeline.**
