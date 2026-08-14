<?php
/**
 * Rotate geometry operation.
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
 * Rotate Operation
 *
 * Rotates the image by the given angle in degrees.
 * Supported options: `angle` (default: 0), `background` (GD: integer color index; Imagick: color string).
 * Supported drivers: GD, Imagick, libvips.
 */
final class Rotate extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'rotate';
    }

    /**
     * Rotates the image via the active driver.
     *
     * @param ImageHandle $handle The image to rotate.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The rotated image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $angle = (float)($this->options['angle'] ?? 0);

        return match (true) {
            $driver instanceof GdDriver => $driver->rotate($handle, $angle, (int)($this->options['background'] ?? 0)),
            $driver instanceof ImagickDriver => $driver->rotate($handle, $angle, (string)($this->options['background'] ?? 'transparent')),
            $driver instanceof LibvipsDriver => $driver->rotate($handle, $angle),
            default => throw new UnsupportedOperationException('Rotate is not supported by the selected driver.'),
        };
    }
}
