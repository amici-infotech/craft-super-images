<?php
/**
 * Filesystem storage adapter for derivative images under a local web-accessible root.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\storage;

use amici\SuperImages\contracts\StorageAdapterInterface;
use amici\SuperImages\exceptions\StorageException;
use amici\SuperImages\models\StorageCapabilities;
use amici\SuperImages\models\StorageObject;
use amici\SuperImages\models\StorageWriteOptions;
use amici\SuperImages\support\PathGuard;
use Craft;

/**
 * Local Storage Adapter
 *
 * Writes derivatives to a configured directory and exposes public URLs via a base URL alias.
 */
final class LocalStorageAdapter implements StorageAdapterInterface
{
    /** @var string Absolute filesystem root for stored objects. */
    private string $_rootPath;

    /** @var string Public base URL for stored objects, without trailing slash. */
    private string $_baseUrl;

    /**
     * @param string $adapterName Logical adapter name from configuration.
     * @param array<string, mixed> $config Adapter settings including `path` and `baseUrl` Craft aliases.
     */
    public function __construct(
        private string $adapterName,
        array $config,
    ) {
        $this->_rootPath = PathGuard::canonicalize(Craft::getAlias((string) ($config['path'] ?? '@webroot/super-images')));
        $this->_baseUrl = rtrim((string) Craft::getAlias((string) ($config['baseUrl'] ?? '@web/super-images')), '/');
    }

    /**
     * Returns the configured adapter name.
     *
     * @return string The logical adapter identifier.
     */
    public function name(): string
    {
        return $this->adapterName;
    }

    /**
     * Writes binary contents to the given relative storage path.
     *
     * @param string $path Relative storage path.
     * @param string $contents Raw file bytes to persist.
     * @param StorageWriteOptions $options Content type and visibility metadata.
     *
     * @return StorageObject Metadata for the stored object including its public URL.
     *
     * @throws StorageException When the file cannot be written.
     */
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

    /**
     * Copies a local file into storage at the given relative path.
     *
     * @param string $path Relative storage path for the destination object.
     * @param string $localFile Absolute path to the readable source file.
     * @param StorageWriteOptions $options Content type and visibility metadata.
     *
     * @return StorageObject Metadata for the stored object including its public URL.
     *
     * @throws StorageException When the source is unreadable or the copy fails.
     */
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

    /**
     * Checks whether an object exists at the given relative path.
     *
     * @param string $path Relative storage path.
     *
     * @return bool True when a regular file exists at the resolved path.
     */
    public function exists(string $path): bool
    {
        return is_file($this->fullPath($path));
    }

    /**
     * Reads the full contents of a stored local file.
     *
     * @param string $path Relative storage path.
     *
     * @return string Raw file bytes.
     *
     * @throws StorageException When the file is missing or unreadable.
     */
    public function read(string $path): string
    {
        $fullPath = $this->fullPath($path);
        if (!is_file($fullPath) || !is_readable($fullPath)) {
            throw new StorageException(sprintf('Local storage object is not readable: %s', $path));
        }

        $contents = file_get_contents($fullPath);
        if ($contents === false) {
            throw new StorageException(sprintf('Failed to read local storage object: %s', $path));
        }

        return $contents;
    }

    /**
     * Returns width/height/size for an existing local derivative when available.
     *
     * @param string $path Relative storage path.
     *
     * @return array{width: int, height: int, size: int}|null Null when the file is missing.
     */
    public function imageMeta(string $path): ?array
    {
        $fullPath = $this->fullPath($path);
        if (!is_file($fullPath)) {
            return null;
        }

        $size = (int) filesize($fullPath);
        $info = @getimagesize($fullPath);

        return [
            'width' => is_array($info) ? (int) ($info[0] ?? 0) : 0,
            'height' => is_array($info) ? (int) ($info[1] ?? 0) : 0,
            'size' => $size,
        ];
    }

    /**
     * Deletes the object at the given relative path when it exists.
     *
     * @param string $path Relative storage path.
     *
     * @return void
     */
    public function delete(string $path): void
    {
        $fullPath = $this->fullPath($path);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    /**
     * Builds the public URL for a stored object.
     *
     * @param string $path Relative storage path.
     *
     * @return string Absolute or root-relative public URL.
     */
    public function url(string $path): string
    {
        return $this->_baseUrl . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Describes local storage capabilities for the pipeline.
     *
     * @return StorageCapabilities Capability flags for this adapter.
     */
    public function capabilities(): StorageCapabilities
    {
        return new StorageCapabilities(
            remote: false,
            publicUrls: true,
            atomicWrite: true,
        );
    }

    /**
     * Resolves a relative storage path to an absolute filesystem path under the root.
     *
     * @param string $path Relative storage path.
     *
     * @return string Absolute filesystem path.
     *
     * @throws StorageException When the path contains traversal segments.
     */
    private function fullPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (str_contains($path, '..')) {
            throw new StorageException('Invalid storage path.');
        }

        return $this->_rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Creates a directory recursively when it does not yet exist.
     *
     * @param string $directory Absolute directory path to ensure.
     *
     * @return void
     *
     * @throws StorageException When the directory cannot be created.
     */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new StorageException('Unable to create storage directory.');
        }
    }
}
