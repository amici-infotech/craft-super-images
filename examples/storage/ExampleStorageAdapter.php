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
 * Example Storage Adapter
 *
 * Stub implementation showing the StorageAdapterInterface contract.
 * Replace the TODO blocks with real HTTP calls to your CDN or object API.
 */
final class ExampleStorageAdapter implements StorageAdapterInterface
{
    /**
     * @param string $adapterName Config handle (e.g. `acme` from config/super-images.php).
     * @param array<string, mixed> $config Adapter settings (`baseUrl`, `prefix`, `apiKey`, …).
     */
    public function __construct(
        private string $adapterName,
        private array $config,
    ) {
    }

    /**
     * Returns the adapter handle from config.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->adapterName;
    }

    /**
     * Writes derivative bytes to remote storage.
     *
     * @param string $path Relative object key built by StoragePathBuilder.
     * @param string $contents Raw file bytes.
     * @param StorageWriteOptions $options Content-Type, Cache-Control, and other headers.
     *
     * @return StorageObject Written object metadata including public URL.
     *
     * @throws StorageException When upload fails.
     */
    public function write(string $path, string $contents, StorageWriteOptions $options = new StorageWriteOptions()): StorageObject
    {
        $this->upload($path, $contents, $options);

        return new StorageObject($path, $this->url($path), strlen($contents), $options->contentType);
    }

    /**
     * Streams a local file into remote storage.
     *
     * @param string $path Relative object key.
     * @param string $localFile Absolute path to a readable local file.
     * @param StorageWriteOptions $options Write headers and metadata.
     *
     * @return StorageObject Written object metadata.
     *
     * @throws StorageException When the source file cannot be read or upload fails.
     */
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

    /**
     * Reads object bytes from remote storage.
     *
     * @param string $path Relative object key.
     *
     * @return string Raw file contents.
     *
     * @throws StorageException When the object cannot be fetched.
     */
    public function read(string $path): string
    {
        // TODO: GET from CDN or origin API
        throw new StorageException('ExampleStorageAdapter::read() is not implemented.');
    }

    /**
     * Checks whether an object exists at the given path.
     *
     * Called only when local existence markers and the asset index miss.
     * Keep this fast — remote HEAD requests add ~300 ms per call on R2/S3.
     *
     * @param string $path Relative object key.
     *
     * @return bool True when the object is present.
     */
    public function exists(string $path): bool
    {
        // TODO: HEAD request or object metadata API
        return false;
    }

    /**
     * Deletes one object from remote storage.
     *
     * @param string $path Relative object key.
     *
     * @return void
     */
    public function delete(string $path): void
    {
        // TODO: DELETE object at $path
    }

    /**
     * Builds the public CDN URL for a stored object.
     *
     * `baseUrl` must match the hostname that actually serves the bucket.
     *
     * @param string $path Relative object key.
     *
     * @return string Absolute public URL.
     */
    public function url(string $path): string
    {
        $base = rtrim((string) ($this->config['baseUrl'] ?? ''), '/');
        $prefix = trim((string) ($this->config['prefix'] ?? ''), '/');
        $key = $prefix !== '' ? $prefix . '/' . ltrim($path, '/') : ltrim($path, '/');

        return $base . '/' . $key;
    }

    /**
     * Declares adapter capabilities for existence checks and delivery.
     *
     * @return StorageCapabilities
     */
    public function capabilities(): StorageCapabilities
    {
        return new StorageCapabilities(remote: true, publicUrls: true, atomicWrite: false);
    }

    /**
     * Uploads bytes to your CDN API.
     *
     * Set Cache-Control on success, e.g. `public, max-age=31536000, immutable`,
     * so browsers and Cloudflare cache derivatives aggressively.
     *
     * @param string $path Relative object key.
     * @param string $contents Raw file bytes.
     * @param StorageWriteOptions $options Content-Type and Cache-Control headers.
     *
     * @return void
     *
     * @throws StorageException When upload fails.
     */
    private function upload(string $path, string $contents, StorageWriteOptions $options): void
    {
        // TODO: PUT $contents using $this->config['apiKey'], etc.
        throw new StorageException(
            'ExampleStorageAdapter::upload() is a stub — copy this class and implement your CDN API.',
        );
    }
}
