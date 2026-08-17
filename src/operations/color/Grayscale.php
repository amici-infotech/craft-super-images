<?php
/**
 * Grayscale color conversion operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\color;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Converts the image to grayscale.
 */
final class Grayscale extends AbstractOperation
{
    public function name(): string
    {
        return 'grayscale';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        return $this->invokeDriver($driver, 'grayscale', $handle);
    }
}
