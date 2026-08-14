<?php
/**
 * Feature flags describing a storage adapter's behaviour.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Storage Capabilities
 *
 * Indicates whether an adapter uses remote storage, exposes public URLs, and supports atomic writes.
 */
final class StorageCapabilities
{
    /**
     * @param bool $remote True when storage is not local filesystem (e.g. S3, FTP).
     * @param bool $publicUrls True when the adapter can resolve publicly accessible URLs.
     * @param bool $atomicWrite True when writes are atomic (temp file + rename).
     */
    public function __construct(
        public readonly bool $remote = false,
        public readonly bool $publicUrls = true,
        public readonly bool $atomicWrite = false,
    ) {
    }
}
