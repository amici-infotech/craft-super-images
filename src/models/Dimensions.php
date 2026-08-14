<?php
/**
 * Width and height pair for image dimensions and pixel budget checks.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Dimensions
 *
 * Simple value object for pixel width and height with helpers for totals and serialization.
 */
final class Dimensions
{
    /**
     * @param int $width Width in pixels.
     * @param int $height Height in pixels.
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    /**
     * Returns the total pixel count (width × height).
     *
     * Used for runtime maxPixels enforcement and diagnostics.
     *
     * @return int Total number of pixels in the image.
     */
    public function pixels(): int
    {
        return $this->width * $this->height;
    }

    /**
     * Serializes dimensions to a plain associative array.
     *
     * @return array{width: int, height: int} Width and height keyed by name.
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
