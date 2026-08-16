<?php
/**
 * Resize geometry operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\geometry;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Resize Operation
 *
 * Resizes an image to the requested dimensions using a fit mode.
 * Supported options: `width`, `height`, `mode` (default: "fit").
 * Supported drivers: GD, Imagick, libvips.
 */
final class Resize extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'resize';
    }

    /**
     * Resizes the image via the active driver.
     *
     * @param ImageHandle $handle The image to resize.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The resized image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $width = isset($this->options['width']) ? (int)$this->options['width'] : null;
        $height = isset($this->options['height']) ? (int)$this->options['height'] : null;
        $mode = (string)($this->options['mode'] ?? 'fit');

        return $this->invokeDriver($driver, 'resize', $handle, $width, $height, $mode);
    }
}
