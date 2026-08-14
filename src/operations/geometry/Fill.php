<?php
/**
 * Fill geometry operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\geometry;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Fill Operation
 *
 * Scales and crops the image to completely fill the target dimensions.
 * Supported options: `width`, `height` (default to current dimensions), `position` (default: "center-center").
 * Supported drivers: GD, Imagick, libvips.
 */
final class Fill extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'fill';
    }

    /**
     * Fills the target dimensions via the active driver.
     *
     * @param ImageHandle $handle The image to transform.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The filled image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $width = (int)($this->options['width'] ?? $handle->width);
        $height = (int)($this->options['height'] ?? $handle->height);
        $position = (string)($this->options['position'] ?? 'center-center');

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver,
            $driver instanceof LibvipsDriver => $driver->fill($handle, $width, $height, $position),
            default => throw new UnsupportedOperationException('Fill is not supported by the selected driver.'),
        };
    }
}
