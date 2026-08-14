<?php
/**
 * Resize geometry operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\geometry;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
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

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver,
            $driver instanceof LibvipsDriver => $driver->resize($handle, $width, $height, $mode),
            default => throw new \amici\SuperImages\exceptions\UnsupportedOperationException('Resize is not supported by the selected driver.'),
        };
    }
}
