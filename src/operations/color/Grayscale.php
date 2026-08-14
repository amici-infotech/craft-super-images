<?php
/**
 * Grayscale color conversion operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\color;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Grayscale Operation
 *
 * Converts the image to grayscale.
 * Supported drivers: GD, Imagick, libvips.
 */
final class Grayscale extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'grayscale';
    }

    /**
     * Converts the image to grayscale via the active driver.
     *
     * @param ImageHandle $handle The image to convert.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The grayscale image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver,
            $driver instanceof LibvipsDriver => $driver->grayscale($handle),
            default => throw new UnsupportedOperationException('Grayscale is not supported by the selected driver.'),
        };
    }
}
