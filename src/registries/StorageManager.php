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
 * Registers storage adapters from config, lazily instantiates local/S3 adapters,
 * and exposes the default adapter name from settings.
 */
class StorageManager extends Component
{
    /**
     * Event fired after config adapters are registered so plugins can add custom backends.
     */
    public const EVENT_REGISTER_STORAGE_ADAPTERS = 'registerStorageAdapters';

    /**
     * Registered adapter instances keyed by adapter name.
     *
     * @var array<string, StorageAdapterInterface>
     */
    private array $_adapters = [];

    /**
     * Raw adapter configuration keyed by adapter name.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $_configs = [];

    /**
     * Register a storage adapter instance and optional config snapshot.
     *
     * @param string $name Adapter handle used in settings and GenerationDefinition.
     * @param StorageAdapterInterface $adapter The adapter instance.
     * @param array<string, mixed> $config Optional config array stored for lazy re-instantiation.
     *
     * @return void
     */
    public function register(string $name, StorageAdapterInterface $adapter, array $config = []): void
    {
        $this->_adapters[$name] = $adapter;
        $this->_configs[$name] = $config;
    }

    /**
     * Register adapters declared in the storage config section.
     *
     * @param array<string, mixed> $storageConfig The storage settings array from plugin config.
     *
     * @return void
     */
    public function registerFromConfig(array $storageConfig): void
    {
        $adapters = $storageConfig['adapters'] ?? [];

        foreach ($adapters as $name => $config) {
            if (!is_array($config)) {
                continue;
            }

            $this->_configs[$name] = $config;

            $type = (string)($config['type'] ?? 'local');
            if ($type === 'local') {
                $this->register($name, new LocalStorageAdapter($name, $config), $config);
            }
        }

        $event = new RegisterStorageAdaptersEvent();
        $this->trigger(self::EVENT_REGISTER_STORAGE_ADAPTERS, $event);

        foreach ($event->adapters as $name => $adapter) {
            $this->register($name, $adapter, $event->configs[$name] ?? []);
        }
    }

    /**
     * Select a storage adapter by name, instantiating it lazily when needed.
     *
     * @param string $name Adapter handle from settings or GenerationDefinition.
     *
     * @return StorageAdapterInterface The ready storage adapter instance.
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

            $type = (string)($config['type'] ?? 'local');
            $adapter = match ($type) {
                'local' => new LocalStorageAdapter($name, $config),
                's3' => new S3CompatibleStorageAdapter($name, $config),
                default => throw new StorageConfigurationException(sprintf('Unknown storage adapter type "%s".', $type)),
            };

            $this->register($name, $adapter, $config);
        }

        return $this->_adapters[$name];
    }

    /**
     * Resolve the default storage adapter name from config.
     *
     * @param array<string, mixed> $storageConfig The storage settings array.
     *
     * @return string The default adapter handle, or local when not set.
     */
    public function defaultName(array $storageConfig): string
    {
        return (string)($storageConfig['default'] ?? 'local');
    }
}
