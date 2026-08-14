<?php
/**
 * Flip geometry operation.
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
 * Flip Operation
 *
 * Mirrors the image horizontally or vertically.
 * Supported options: `direction` (default: "horizontal").
 * Supported drivers: GD, Imagick, libvips.
 */
final class Flip extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'flip';
    }

    /**
     * Flips the image via the active driver.
     *
     * @param ImageHandle $handle The image to flip.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The flipped image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $direction = (string)($this->options['direction'] ?? 'horizontal');

        return match (true) {
            $driver instanceof GdDriver,
            $driver instanceof ImagickDriver,
            $driver instanceof LibvipsDriver => $driver->flip($handle, $direction),
            default => throw new UnsupportedOperationException('Flip is not supported by the selected driver.'),
        };
    }
}
