<?php
/**
 * Driver-specific wrapper around a loaded in-memory image resource.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Image Handle
 *
 * Carries the native driver resource plus dimensions, alpha state, and MIME metadata through the pipeline.
 */
final class ImageHandle
{
    /**
     * @param string $driverName Name of the driver that created this handle.
     * @param mixed $resource Native driver object (e.g. VipsImage, Imagick, GdImage).
     * @param int $width Current width in pixels.
     * @param int $height Current height in pixels.
     * @param bool $hasAlpha Whether the image has an alpha channel.
     * @param string $mime MIME type of the loaded source or current representation.
     */
    public function __construct(
        public readonly string $driverName,
        public mixed $resource,
        public int $width,
        public int $height,
        public bool $hasAlpha,
        public string $mime,
    ) {
    }

    /**
     * Returns a copy of this handle with updated dimensions but the same resource and metadata.
     *
     * Used after transforms that change pixel dimensions without replacing the driver resource.
     *
     * @param int $width New width in pixels.
     * @param int $height New height in pixels.
     *
     * @return self New handle sharing the same resource with updated dimensions.
     */
    public function withDimensions(int $width, int $height): self
    {
        return new self(
            $this->driverName,
            $this->resource,
            $width,
            $height,
            $this->hasAlpha,
            $this->mime,
        );
    }
}
