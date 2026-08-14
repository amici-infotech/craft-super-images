<?php
/**
 * Contract for persisting generated image derivatives to a storage backend.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\contracts;

use amici\SuperImages\models\StorageCapabilities;
use amici\SuperImages\models\StorageObject;
use amici\SuperImages\models\StorageWriteOptions;

/**
 * Storage Adapter Interface
 *
 * Defines read/write/delete operations for derivative files (local disk, S3, etc.).
 */
interface StorageAdapterInterface
{
    /**
     * Returns the adapter identifier used in configuration and diagnostics.
     *
     * @return string Adapter name (e.g. `local`, `s3`).
     */
    public function name(): string;

    /**
     * Writes binary contents to the given storage path.
     *
     * @param string $path Relative storage path for the derivative.
     * @param string $contents Raw file bytes to persist.
     * @param StorageWriteOptions $options Content type, visibility, and optional metadata.
     *
     * @return StorageObject Persisted object with path, public URL, size, and MIME type.
     */
    public function write(string $path, string $contents, StorageWriteOptions $options = new StorageWriteOptions()): StorageObject;

    /**
     * Streams a local file into storage without loading it entirely into memory.
     *
     * @param string $path Relative storage path for the derivative.
     * @param string $localFile Absolute filesystem path to the source file.
     * @param StorageWriteOptions $options Content type, visibility, and optional metadata.
     *
     * @return StorageObject Persisted object with path, public URL, size, and MIME type.
     */
    public function writeFile(string $path, string $localFile, StorageWriteOptions $options = new StorageWriteOptions()): StorageObject;

    /**
     * Checks whether a derivative already exists at the given path.
     *
     * @param string $path Relative storage path to check.
     *
     * @return bool True when the object is present in storage.
     */
    public function exists(string $path): bool;

    /**
     * Removes a derivative from storage.
     *
     * @param string $path Relative storage path to delete.
     */
    public function delete(string $path): void;

    /**
     * Resolves the publicly accessible URL for a stored derivative.
     *
     * @param string $path Relative storage path.
     *
     * @return string Absolute URL suitable for delivery or `<img src>`.
     */
    public function url(string $path): string;

    /**
     * Describes adapter features such as remote storage and atomic writes.
     *
     * @return StorageCapabilities Capability flags for this adapter.
     */
    public function capabilities(): StorageCapabilities;
}
