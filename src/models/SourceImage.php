<?php
/**
 * Resolved source image ready for loading by an image driver.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Source Image
 *
 * Represents fetched or resolved source data with identity, optional dimensions, and Craft metadata.
 */
final class SourceImage
{
    /**
     * @param SourceKind $kind How the source was resolved (asset, local path, or remote URL).
     * @param string $identity Stable hash identifying this source for derivative cache keys.
     * @param string|null $path Absolute filesystem path when the source is readable on disk.
     * @param string|null $bytes Raw source bytes when loaded into memory.
     * @param string|null $mime Detected or declared MIME type of the source.
     * @param int|null $width Known source width in pixels before loading.
     * @param int|null $height Known source height in pixels before loading.
     * @param bool $isTemporary True when `$path` is a temp file that should be cleaned up after use.
     * @param array<string, mixed> $metadata Additional context (asset ID, volume, focal point, etc.).
     */
    public function __construct(
        public readonly SourceKind $kind,
        public readonly string $identity,
        public readonly ?string $path = null,
        public readonly ?string $bytes = null,
        public readonly ?string $mime = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly bool $isTemporary = false,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Returns the filesystem path when it exists and is readable by the current process.
     *
     * @return string|null Absolute path, or null when no readable path is available.
     */
    public function readablePath(): ?string
    {
        if ($this->path !== null && is_readable($this->path)) {
            return $this->path;
        }

        return null;
    }
}
