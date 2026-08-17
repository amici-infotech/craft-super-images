<?php
/**
 * Reference storage adapter for a fictional HTTP/CDN backend.
 *
 * Super Images builds deterministic paths — you only implement read/write/exists/delete/url.
 * For production S3/GCS/R2, prefer extending the pattern in S3CompatibleStorageAdapter.
 */

namespace myagency\superimages\examples\storage;

use amici\SuperImages\contracts\StorageAdapterInterface;
use amici\SuperImages\exceptions\StorageException;
use amici\SuperImages\models\StorageCapabilities;
use amici\SuperImages\models\StorageObject;
use amici\SuperImages\models\StorageWriteOptions;

/**
 * Stores derivatives via your CDN API. Replace the TODO blocks with real HTTP calls.
 */
final class ExampleStorageAdapter implements StorageAdapterInterface
{
    public function __construct(
        private string $adapterName,
        private array $config,
    ) {
    }

    public function name(): string
    {
        return $this->adapterName;
    }

    public function write(string $path, string $contents, StorageWriteOptions $options = new StorageWriteOptions()): StorageObject
    {
        $this->upload($path, $contents, $options);

        return new StorageObject($path, $this->url($path), strlen($contents), $options->contentType);
    }

    public function writeFile(string $path, string $localFile, StorageWriteOptions $options = new StorageWriteOptions()): StorageObject
    {
        if (!is_readable($localFile)) {
            throw new StorageException('Source file is not readable.');
        }

        $contents = file_get_contents($localFile);
        if ($contents === false) {
            throw new StorageException('Failed to read source file.');
        }

        return $this->write($path, $contents, $options);
    }

    public function read(string $path): string
    {
        // TODO: GET from CDN or origin API
        throw new StorageException('ExampleStorageAdapter::read() is not implemented.');
    }

    public function exists(string $path): bool
    {
        // TODO: HEAD request or object metadata API
        return false;
    }

    public function delete(string $path): void
    {
        // TODO: DELETE object at $path
    }

    public function url(string $path): string
    {
        $base = rtrim((string) ($this->config['baseUrl'] ?? ''), '/');
        $prefix = trim((string) ($this->config['prefix'] ?? ''), '/');
        $key = $prefix !== '' ? $prefix . '/' . ltrim($path, '/') : ltrim($path, '/');

        return $base . '/' . $key;
    }

    public function capabilities(): StorageCapabilities
    {
        return new StorageCapabilities(remote: true, publicUrls: true, atomicWrite: false);
    }

    /**
     * @throws StorageException When upload fails.
     */
    private function upload(string $path, string $contents, StorageWriteOptions $options): void
    {
        // TODO: PUT $contents to your bucket/API using $this->config['apiKey'], etc.
        // Set Cache-Control on success, e.g. public, max-age=31536000, immutable
        throw new StorageException(
            'ExampleStorageAdapter::upload() is a stub — copy this class and implement your CDN API.',
        );
    }
}
