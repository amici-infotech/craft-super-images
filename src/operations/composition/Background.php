<?php
/**
 * Background fill composition operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\composition;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Background Operation
 *
 * Fills transparent areas of the image with a solid background color.
 * Supported options: `color` (default: "#ffffff").
 * Supported drivers: GD, Imagick.
 */
final class Background extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'background';
    }

    /**
     * Applies the background color via the active driver.
     *
     * @param ImageHandle $handle The image to fill.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The image handle with background applied.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $color = (string)($this->options['color'] ?? '#ffffff');

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver => $driver->background($handle, $color),
            default => throw new UnsupportedOperationException('Background is not supported by the selected driver.'),
        };
    }
}
