<?php
/**
 * Fit geometry operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\geometry;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Fit Operation
 *
 * Scales the image to fit within a bounding box while preserving aspect ratio.
 * Supported options: `width`, `height`.
 * Supported drivers: GD, Imagick, libvips.
 */
final class Fit extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'fit';
    }

    /**
     * Fits the image within the target dimensions via the active driver.
     *
     * @param ImageHandle $handle The image to fit.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The fitted image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $width = isset($this->options['width']) ? (int)$this->options['width'] : null;
        $height = isset($this->options['height']) ? (int)$this->options['height'] : null;

        return $this->invokeDriver($driver, 'fit', $handle, $width, $height);
    }
}
