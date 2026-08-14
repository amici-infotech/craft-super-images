<?php
/**
 * Metadata returned after a successful storage write.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Storage Object
 *
 * Represents a persisted derivative with its path, URL, size, and MIME type.
 */
final class StorageObject
{
    /**
     * @param string $path Relative storage path of the written file.
     * @param string $url Public URL for the stored object.
     * @param int $size File size in bytes after write.
     * @param string $mime MIME type of the stored file.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $url,
        public readonly int $size,
        public readonly string $mime,
    ) {
    }
}
