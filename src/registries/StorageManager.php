<?php
/**
 * Registry and factory for derivative storage adapters.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\registries;

use amici\SuperImages\contracts\StorageAdapterInterface;
use amici\SuperImages\events\RegisterStorageAdaptersEvent;
use amici\SuperImages\exceptions\StorageConfigurationException;
use amici\SuperImages\storage\LocalStorageAdapter;
use amici\SuperImages\storage\S3CompatibleStorageAdapter;
use yii\base\Component;

/**
 * Storage Manager
 *
 * Registers adapters from config + events, supports custom `type` factories,
 * and lazily instantiates local/S3 (and third-party) adapters.
 */
class StorageManager extends Component
{
    public const EVENT_REGISTER_STORAGE_ADAPTERS = 'registerStorageAdapters';

    /** @var array<string, StorageAdapterInterface> */
    private array $_adapters = [];

    /** @var array<string, array<string, mixed>> */
    private array $_configs = [];

    /**
     * Factories for config `type` values.
     *
     * @var array<string, callable(string, array<string, mixed>): StorageAdapterInterface>
     */
    private array $_types = [];

    /**
     * Register a storage adapter instance and optional config snapshot.
     *
     * @param string $name Adapter handle used in settings and GenerationDefinition.
     * @param StorageAdapterInterface $adapter The adapter instance.
     * @param array<string, mixed> $config Optional config array stored for lazy re-instantiation.
     */
    public function register(string $name, StorageAdapterInterface $adapter, array $config = []): void
    {
        $this->_adapters[$name] = $adapter;
        $this->_configs[$name] = $config;
    }

    /**
     * Register a factory for a storage `type` string used in config.
     *
     * @param string $type Type key (e.g. `gcs`).
     * @param callable(string, array<string, mixed>): StorageAdapterInterface $factory
     */
    public function registerType(string $type, callable $factory): void
    {
        $this->_types[strtolower($type)] = $factory;
    }

    /**
     * Register adapters declared in the storage config section, then fire the extension event.
     *
     * @param array<string, mixed> $storageConfig The storage settings array from plugin config.
     */
    public function registerFromConfig(array $storageConfig): void
    {
        $s3Factory = static fn(string $name, array $config): StorageAdapterInterface => new S3CompatibleStorageAdapter($name, $config);
        $this->_types['local'] = static fn(string $name, array $config): StorageAdapterInterface => new LocalStorageAdapter($name, $config);
        $this->_types['s3'] = $s3Factory;
        // DigitalOcean Spaces and other S3-compatible providers share the same adapter.
        $this->_types['spaces'] = $s3Factory;
        $this->_types['r2'] = $s3Factory;

        $adapters = $storageConfig['adapters'] ?? [];

        foreach ($adapters as $name => $config) {
            if (!is_array($config)) {
                continue;
            }
            $this->_configs[(string) $name] = $config;
        }

        $event = new RegisterStorageAdaptersEvent();
        $this->trigger(self::EVENT_REGISTER_STORAGE_ADAPTERS, $event);

        foreach ($event->types as $type => $factory) {
            $this->registerType((string) $type, $factory);
        }

        foreach ($event->adapters as $name => $adapter) {
            $this->register((string) $name, $adapter, $event->configs[$name] ?? $this->_configs[$name] ?? []);
        }

        // Eager-create local adapters so path/baseUrl issues surface early.
        foreach ($this->_configs as $name => $config) {
            if (isset($this->_adapters[$name])) {
                continue;
            }
            $type = strtolower((string)($config['type'] ?? 'local'));
            if ($type === 'local' && isset($this->_types['local'])) {
                $this->register($name, ($this->_types['local'])($name, $config), $config);
            }
        }
    }

    /**
     * Select a storage adapter by name, instantiating it lazily when needed.
     *
     * @param string $name Adapter handle from settings or GenerationDefinition.
     *
     * @throws StorageConfigurationException When the adapter is unknown or has an unsupported type.
     */
    public function select(string $name): StorageAdapterInterface
    {
        if (!isset($this->_adapters[$name])) {
            $config = $this->_configs[$name] ?? null;
            if ($config === null) {
                throw new StorageConfigurationException(sprintf('Storage adapter "%s" is not registered.', $name));
            }

            $type = strtolower((string)($config['type'] ?? 'local'));
            $factory = $this->_types[$type] ?? null;
            if ($factory === null) {
                throw new StorageConfigurationException(sprintf(
                    'Unknown storage adapter type "%s". Register a type factory via %s::$types.',
                    $type,
                    RegisterStorageAdaptersEvent::class,
                ));
            }

            $this->register($name, $factory($name, $config), $config);
        }

        return $this->_adapters[$name];
    }

    /**
     * Resolve the default storage adapter name from config.
     *
     * @param array<string, mixed> $storageConfig The storage settings array.
     */
    public function defaultName(array $storageConfig): string
    {
        return (string)($storageConfig['default'] ?? 'local');
    }
}
