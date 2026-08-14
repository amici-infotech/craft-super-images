<?php

namespace amici\SuperImages\registries;

use amici\SuperImages\contracts\StorageAdapterInterface;
use amici\SuperImages\events\RegisterStorageAdaptersEvent;
use amici\SuperImages\exceptions\StorageConfigurationException;
use amici\SuperImages\storage\LocalStorageAdapter;
use amici\SuperImages\storage\S3CompatibleStorageAdapter;
use yii\base\Component;

/**
 * Storage Manager
 */
class StorageManager extends Component
{
    public const EVENT_REGISTER_STORAGE_ADAPTERS = 'registerStorageAdapters';

    /** @var array<string, StorageAdapterInterface> */
    private array $_adapters = [];

    /** @var array<string, array<string, mixed>> */
    private array $_configs = [];

    public function register(string $name, StorageAdapterInterface $adapter, array $config = []): void
    {
        $this->_adapters[$name] = $adapter;
        $this->_configs[$name] = $config;
    }

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

    public function defaultName(array $storageConfig): string
    {
        return (string)($storageConfig['default'] ?? 'local');
    }
}
