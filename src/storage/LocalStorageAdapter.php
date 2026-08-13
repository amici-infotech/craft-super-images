<?php

namespace amici\SuperImages\storage;

use amici\SuperImages\contracts\StorageAdapterInterface;
use amici\SuperImages\exceptions\StorageException;
use amici\SuperImages\models\StorageCapabilities;
use amici\SuperImages\models\StorageObject;
use amici\SuperImages\models\StorageWriteOptions;
use amici\SuperImages\support\PathGuard;
use Craft;

final class LocalStorageAdapter implements StorageAdapterInterface
{
    private string $_rootPath;
    private string $_baseUrl;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private string $adapterName,
        array $config,
    ) {
        $this->_rootPath = PathGuard::canonicalize(Craft::getAlias((string) ($config['path'] ?? '@webroot/super-images')));
        $this->_baseUrl = rtrim((string) Craft::getAlias((string) ($config['baseUrl'] ?? '@web/super-images')), '/');
    }

    public function name(): string
    {
        return $this->adapterName;
    }

    public function write(string $path, string $contents, StorageWriteOptions $options = new StorageWriteOptions()): StorageObject
    {
        $fullPath = $this->fullPath($path);
        $this->ensureDirectory(dirname($fullPath));

        if (file_put_contents($fullPath, $contents) === false) {
            throw new StorageException('Failed to write local storage object.');
        }

        @chmod($fullPath, 0644);

        return new StorageObject(
            $path,
            $this->url($path),
            strlen($contents),
            $options->contentType,
        );
    }

    public function writeFile(string $path, string $localFile, StorageWriteOptions $options = new StorageWriteOptions()): StorageObject
    {
        $fullPath = $this->fullPath($path);
        $this->ensureDirectory(dirname($fullPath));

        if (!is_readable($localFile)) {
            throw new StorageException('Local source file is not readable.');
        }

        if (!copy($localFile, $fullPath)) {
            throw new StorageException('Failed to copy file to local storage.');
        }

        @chmod($fullPath, 0644);

        return new StorageObject(
            $path,
            $this->url($path),
            (int)filesize($fullPath),
            $options->contentType,
        );
    }

    public function exists(string $path): bool
    {
        return is_file($this->fullPath($path));
    }

    public function delete(string $path): void
    {
        $fullPath = $this->fullPath($path);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    public function url(string $path): string
    {
        return $this->_baseUrl . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    public function capabilities(): StorageCapabilities
    {
        return new StorageCapabilities(
            remote: false,
            publicUrls: true,
            atomicWrite: true,
        );
    }

    private function fullPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (str_contains($path, '..')) {
            throw new StorageException('Invalid storage path.');
        }

        return $this->_rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new StorageException('Unable to create storage directory.');
        }
    }
}
