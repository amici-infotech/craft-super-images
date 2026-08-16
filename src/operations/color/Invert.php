<?php
/**
 * Invert color operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\color;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Invert Operation
 *
 * Inverts the colors of the image (negative effect).
 * Supported drivers: GD, Imagick.
 */
final class Invert extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'invert';
    }

    /**
     * Inverts the image colors via the active driver.
     *
     * @param ImageHandle $handle The image to invert.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The inverted image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        return $this->invokeDriver($driver, 'invert', $handle);
    }
}
