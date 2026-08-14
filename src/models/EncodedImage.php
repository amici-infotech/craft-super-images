<?php
/**
 * Encoded image payload with optional in-memory bytes or temp file path.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Encoded Image
 *
 * Holds the output of encoding or optimization, referencing either raw bytes or a filesystem path.
 */
final class EncodedImage
{
    /**
     * @param string $format Output format slug (e.g. `webp`, `jpeg`).
     * @param int $width Encoded image width in pixels.
     * @param int $height Encoded image height in pixels.
     * @param int $size Payload size in bytes.
     * @param string $mime MIME type of the encoded data.
     * @param string|null $bytes Raw encoded bytes when held in memory.
     * @param string|null $path Absolute path to a temp or output file when not in memory.
     * @param bool $isTemporary True when `$path` points to a temp file that should be cleaned up.
     */
    public function __construct(
        public readonly string $format,
        public readonly int $width,
        public readonly int $height,
        public readonly int $size,
        public readonly string $mime,
        public readonly ?string $bytes = null,
        public readonly ?string $path = null,
        public readonly bool $isTemporary = false,
    ) {
    }

    /**
     * Whether encoded data is available as an in-memory byte string.
     *
     * @return bool True when `$bytes` is non-empty.
     */
    public function hasBytes(): bool
    {
        return $this->bytes !== null && $this->bytes !== '';
    }

    /**
     * Whether encoded data is available on disk at `$path`.
     *
     * @return bool True when `$path` is non-empty.
     */
    public function hasPath(): bool
    {
        return $this->path !== null && $this->path !== '';
    }

    /**
     * Returns a copy of this image with in-memory bytes and no filesystem path.
     *
     * @param string $bytes Raw encoded payload.
     * @param int $size Byte length of the payload.
     *
     * @return self New instance with bytes set, path cleared, and `isTemporary` false.
     */
    public function withBytes(string $bytes, int $size): self
    {
        return new self(
            $this->format,
            $this->width,
            $this->height,
            $size,
            $this->mime,
            $bytes,
            null,
            false,
        );
    }

    /**
     * Returns a copy of this image referencing a filesystem path instead of bytes.
     *
     * @param string $path Absolute path to the encoded file.
     * @param int $size File size in bytes.
     * @param bool $isTemporary Whether the path is a temp file eligible for cleanup.
     *
     * @return self New instance with path set, bytes cleared, and the given temporary flag.
     */
    public function withPath(string $path, int $size, bool $isTemporary = false): self
    {
        return new self(
            $this->format,
            $this->width,
            $this->height,
            $size,
            $this->mime,
            null,
            $path,
            $isTemporary,
        );
    }
}
