<?php
/**
 * Options passed to storage adapters when writing derivative files.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Storage Write Options
 *
 * Controls content type, visibility, and optional adapter-specific metadata on write.
 */
final class StorageWriteOptions
{
    /**
     * @param string $contentType MIME type stored with the object (HTTP Content-Type).
     * @param bool $public Whether the object should be publicly readable when the adapter supports ACLs.
     * @param array<string, string> $metadata Optional key/value metadata for remote adapters.
     */
    public function __construct(
        public readonly string $contentType = 'application/octet-stream',
        public readonly bool $public = true,
        public readonly array $metadata = [],
    ) {
    }
}
