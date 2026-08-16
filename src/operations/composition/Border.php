<?php
/**
 * Border composition operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\composition;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Border Operation
 *
 * Draws a solid border around the image.
 * Supported options: `size` (default: 1), `color` (default: "#000000").
 * Supported drivers: GD, Imagick.
 */
final class Border extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'border';
    }

    /**
     * Adds a border to the image via the active driver.
     *
     * @param ImageHandle $handle The image to border.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The bordered image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $size = (int)($this->options['size'] ?? 1);
        $color = (string)($this->options['color'] ?? '#000000');

        return $this->invokeDriver($driver, 'border', $handle, $size, $color);
    }
}
